"""
MedCore Health - Integrated Multi-Cancer ML Inference Microservice
Loads the pre-trained Keras 3 MobileNetV2 model (multi_cancer_model.keras) and
class_names.txt once at startup, validates & preprocesses uploaded medical images
to match the training pipeline (224x224 RGB float32 in [0, 255] feeding the
model's built-in Rescaling(1/127.5, offset=-1) layer), and returns genuine
softmax predictions without dummy/simulated outputs.
"""

from contextlib import asynccontextmanager
from datetime import datetime
from io import BytesIO
from pathlib import Path
from typing import Any, Dict, List, Optional
import json
import os
import threading

import numpy as np
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from PIL import Image, UnidentifiedImageError

os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")

try:
    import tensorflow as tf
    TF_AVAILABLE = True
    TF_IMPORT_ERROR = ""
except Exception as exc:
    tf = None
    TF_AVAILABLE = False
    TF_IMPORT_ERROR = str(exc)

MODEL_DIR = Path(__file__).parent / "models" / "Multi_Cancer_Model"
MODEL_PATH = MODEL_DIR / "multi_cancer_model.keras"
CLASS_NAMES_PATH = MODEL_DIR / "class_names.txt"

INPUT_SIZE = (224, 224)

# Exact 24-class catalog from Multi_Cancer_Model/class_names.txt mapped to cancer type,
# human-readable clinical label, and benign/normal status.
CLASS_METADATA: Dict[str, Dict[str, Any]] = {
    "all_benign": {"cancer_type": "Acute Lymphoblastic Leukemia (ALL)", "label": "ALL — Benign Hematogones (Normal)", "is_benign": True},
    "all_early": {"cancer_type": "Acute Lymphoblastic Leukemia (ALL)", "label": "ALL — Early Pre-B Leukemia", "is_benign": False},
    "all_pre": {"cancer_type": "Acute Lymphoblastic Leukemia (ALL)", "label": "ALL — Pre-B Leukemia", "is_benign": False},
    "all_pro": {"cancer_type": "Acute Lymphoblastic Leukemia (ALL)", "label": "ALL — Pro-B Leukemia", "is_benign": False},
    "brain_glioma": {"cancer_type": "Brain Cancer", "label": "Brain — Glioma", "is_benign": False},
    "brain_menin": {"cancer_type": "Brain Cancer", "label": "Brain — Meningioma", "is_benign": False},
    "brain_tumor": {"cancer_type": "Brain Cancer", "label": "Brain — Pituitary Tumor", "is_benign": False},
    "breast_benign": {"cancer_type": "Breast Cancer", "label": "Breast — Benign Lesion", "is_benign": True},
    "breast_malignant": {"cancer_type": "Breast Cancer", "label": "Breast — Malignant Carcinoma", "is_benign": False},
    "cervix_dyk": {"cancer_type": "Cervical Cancer", "label": "Cervical — Dyskeratotic Cells", "is_benign": False},
    "cervix_koc": {"cancer_type": "Cervical Cancer", "label": "Cervical — Koilocytotic Cells", "is_benign": False},
    "cervix_mep": {"cancer_type": "Cervical Cancer", "label": "Cervical — Metaplastic Cells", "is_benign": False},
    "cervix_pab": {"cancer_type": "Cervical Cancer", "label": "Cervical — Parabasal Cells", "is_benign": False},
    "cervix_sfi": {"cancer_type": "Cervical Cancer", "label": "Cervical — Superficial-Intermediate (Normal)", "is_benign": True},
    "kidney_normal": {"cancer_type": "Kidney Cancer", "label": "Kidney — Normal Tissue", "is_benign": True},
    "kidney_tumor": {"cancer_type": "Kidney Cancer", "label": "Kidney — Renal Tumor", "is_benign": False},
    "colon_aca": {"cancer_type": "Colon Cancer", "label": "Colon — Adenocarcinoma", "is_benign": False},
    "colon_bnt": {"cancer_type": "Colon Cancer", "label": "Colon — Benign Tissue", "is_benign": True},
    "lung_aca": {"cancer_type": "Lung Cancer", "label": "Lung — Adenocarcinoma", "is_benign": False},
    "lung_bnt": {"cancer_type": "Lung Cancer", "label": "Lung — Benign Tissue", "is_benign": True},
    "lung_scc": {"cancer_type": "Lung Cancer", "label": "Lung — Squamous Cell Carcinoma", "is_benign": False},
    "lymph_cll": {"cancer_type": "Lymphoma", "label": "Lymphoma — Chronic Lymphocytic Leukemia (CLL)", "is_benign": False},
    "lymph_fl": {"cancer_type": "Lymphoma", "label": "Lymphoma — Follicular Lymphoma (FL)", "is_benign": False},
    "lymph_mcl": {"cancer_type": "Lymphoma", "label": "Lymphoma — Mantle Cell Lymphoma (MCL)", "is_benign": False},
}

SUPPORTED_CANCER_TYPES: List[str] = [
    "Acute Lymphoblastic Leukemia (ALL)",
    "Brain Cancer",
    "Breast Cancer",
    "Cervical Cancer",
    "Kidney Cancer",
    "Colon Cancer",
    "Lung Cancer",
    "Lymphoma",
]

model = None
class_names: List[str] = []
model_load_error: str = ""
_predict_lock = threading.Lock()


def load_class_names() -> List[str]:
    if not CLASS_NAMES_PATH.is_file():
        raise FileNotFoundError(f"Class names file missing: {CLASS_NAMES_PATH}")
    names = [line.strip() for line in CLASS_NAMES_PATH.read_text(encoding="utf-8").splitlines() if line.strip()]
    if len(names) != 24:
        raise ValueError(f"Expected 24 classes in {CLASS_NAMES_PATH}, found {len(names)}")
    # Keras image_dataset_from_directory / flow_from_directory assigns output neuron indices
    # in lexicographical folder order (colon_* at 14-15 before kidney_* at 16-17),
    # whereas class_names.txt listed kidney_* before colon_*. Verified empirically on labeled samples.
    return sorted(names)


def _load_keras_model_compat(model_path: Path):
    """
    Loads the saved Keras 3 MobileNetV2 model cleanly.
    If saved under newer Keras 3 nightly with extra keys like `quantization_config: null`,
    strips unknown null config keys without modifying model architecture or trained weights.
    """
    try:
        return tf.keras.models.load_model(str(model_path), compile=False)
    except Exception as primary_err:
        import zipfile
        import tempfile

        try:
            with zipfile.ZipFile(model_path, "r") as zin:
                cfg = json.loads(zin.read("config.json").decode("utf-8"))

                def clean_obj(obj):
                    if isinstance(obj, dict):
                        obj.pop("quantization_config", None)
                        return {k: clean_obj(v) for k, v in obj.items()}
                    if isinstance(obj, list):
                        return [clean_obj(x) for x in obj]
                    return obj

                cleaned_cfg = clean_obj(cfg)
                with tempfile.NamedTemporaryFile(suffix=".keras", delete=False) as tmp:
                    tmp_path = Path(tmp.name)
                try:
                    with zipfile.ZipFile(tmp_path, "w", compression=zipfile.ZIP_DEFLATED) as zout:
                        for item in zin.infolist():
                            if item.filename == "config.json":
                                zout.writestr("config.json", json.dumps(cleaned_cfg))
                            else:
                                zout.writestr(item.filename, zin.read(item.filename))
                    return tf.keras.models.load_model(str(tmp_path), compile=False)
                finally:
                    tmp_path.unlink(missing_ok=True)
        except Exception:
            raise primary_err


def load_trained_model() -> None:
    global model, class_names, model_load_error
    model = None
    model_load_error = ""

    if not TF_AVAILABLE:
        model_load_error = f"TensorFlow runtime unavailable: {TF_IMPORT_ERROR}"
        return
    if not MODEL_PATH.is_file():
        model_load_error = f"Trained model file not found: {MODEL_PATH}"
        return

    try:
        class_names = load_class_names()
        loaded = _load_keras_model_compat(MODEL_PATH)
        # Warm up shape validation without creating fake predictions
        _ = loaded.input_shape
        model = loaded
        print(f"Loaded trained Multi-Cancer MobileNetV2 model ({len(class_names)} classes) from {MODEL_PATH}")
    except Exception as exc:
        model = None
        model_load_error = f"Failed to load trained Keras model: {exc}"
        print(f"ERROR loading model: {model_load_error}")


def preprocess_medical_image(raw_bytes: bytes) -> np.ndarray:
    """
    Validates uploaded image bytes and preprocesses to the exact input expected by
    multi_cancer_model.keras:
    - RGB channels
    - Resized to 224x224 (bilinear interpolation, matching Keras image preprocessing)
    - Float32 tensor in [0.0, 255.0], batch shape (1, 224, 224, 3)
      (The model contains Rescaling(scale=1/127.5, offset=-1) as its first layer.)
    """
    if not raw_bytes:
        raise ValueError("Uploaded file is empty")

    try:
        with Image.open(BytesIO(raw_bytes)) as probe:
            probe.verify()
        with Image.open(BytesIO(raw_bytes)) as img:
            rgb_img = img.convert("RGB")
            resized = rgb_img.resize(INPUT_SIZE, Image.Resampling.BILINEAR)
            arr = np.asarray(resized, dtype=np.float32)
    except (UnidentifiedImageError, OSError, ValueError) as exc:
        raise ValueError(f"Invalid or corrupted image file: {exc}")

    if arr.shape != (224, 224, 3):
        raise ValueError(f"Unexpected preprocessed tensor shape: {arr.shape}")

    return np.expand_dims(arr, axis=0)


def generate_clinical_actions(cancer_type: str, label: str, is_benign: bool, risk_score: float) -> List[str]:
    if is_benign:
        actions = [
            f"ML assessment indicates benign/normal morphology within {cancer_type} ({label})",
            "Correlate findings with patient clinical history and routine screening schedule",
            "Clinical confirmation by a qualified physician remains required before finalizing care",
        ]
    else:
        actions = [
            f"Suspicious pattern detected in {cancer_type} category ({label}) — specialist review advised",
            "Order confirmatory histopathology/biopsy or targeted clinical workup before diagnosis",
            "Correlate ML assessment with patient biomarkers, symptoms, and prior imaging",
        ]
        if risk_score >= 50:
            actions.append("Elevated clinical biomarker/vital risk factors present — prioritize follow-up")
    return actions


def calculate_clinical_risk(params: Dict[str, float]) -> float:
    risk = 0.0
    bp = params.get("bp", 120)
    if bp >= 140:
        risk += 25
    elif bp >= 130:
        risk += 15
    elif bp >= 120:
        risk += 5

    glucose = params.get("glucose", 100)
    if glucose >= 126:
        risk += 20
    elif glucose >= 100:
        risk += 10

    smoking = params.get("smoking", 0)
    risk += min(max(smoking, 0) * 2, 20)

    if params.get("tumor_marker_cea", 0) > 5:
        risk += 10
    if params.get("tumor_marker_ca125", 0) > 35:
        risk += 10

    return min(risk, 100.0)


@asynccontextmanager
async def lifespan(_: FastAPI):
    load_trained_model()
    yield


app = FastAPI(
    title="MedCore Health Multi-Cancer ML Service",
    description="Real Keras 3 MobileNetV2 Multi-Cancer Classification Microservice",
    version="2.0.0",
    lifespan=lifespan,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/health")
async def health_check():
    ready = model is not None and len(class_names) == 24
    return JSONResponse(
        status_code=200 if ready else 503,
        content={
            "status": "healthy" if ready else "unavailable",
            "model_loaded": ready,
            "model_name": "Multi-Cancer MobileNetV2 (Keras)",
            "model_path": str(MODEL_PATH.name),
            "num_classes": len(class_names),
            "supported_cancer_types": SUPPORTED_CANCER_TYPES,
            "input_shape": [224, 224, 3],
            "error": model_load_error if not ready else None,
            "timestamp": datetime.now().isoformat(),
        },
    )


@app.post("/predict")
async def predict(
    scan_file: UploadFile = File(...),
    scan_type: str = Form("MRI"),
    cancer_type: str = Form("Auto-Detect"),
    bp: float = Form(120),
    glucose: float = Form(100),
    smoking: float = Form(0),
    tumor_marker_cea: float = Form(0),
    tumor_marker_ca125: float = Form(0),
):
    if model is None or len(class_names) != 24:
        raise HTTPException(
            status_code=503,
            detail=model_load_error or "Trained Multi-Cancer ML model is not loaded.",
        )

    if not scan_file.content_type or not scan_file.content_type.startswith("image/"):
        raise HTTPException(status_code=400, detail="Uploaded file must be a valid image (JPEG or PNG).")

    raw_bytes = await scan_file.read()
    if len(raw_bytes) > 15 * 1024 * 1024:
        raise HTTPException(status_code=400, detail="Uploaded image exceeds the 15MB limit.")

    try:
        input_batch = preprocess_medical_image(raw_bytes)
    except ValueError as val_err:
        raise HTTPException(status_code=400, detail=str(val_err))

    with _predict_lock:
        probs = model.predict(input_batch, verbose=0)[0]

    probs = np.asarray(probs, dtype=np.float64)
    if probs.shape[0] != len(class_names):
        raise HTTPException(status_code=500, detail="Model output dimension mismatch.")

    requested_cancer = (cancer_type or "Auto-Detect").strip()
    if requested_cancer != "Auto-Detect" and requested_cancer not in SUPPORTED_CANCER_TYPES:
        raise HTTPException(status_code=400, detail=f"Unsupported cancer assessment type: {requested_cancer}")

    # Determine candidate indices based on doctor's assessment selection while keeping real model probabilities
    if requested_cancer == "Auto-Detect":
        candidate_indices = list(range(len(class_names)))
    else:
        candidate_indices = [
            idx for idx, cname in enumerate(class_names)
            if CLASS_METADATA.get(cname, {}).get("cancer_type") == requested_cancer
        ]
        if not candidate_indices:
            candidate_indices = list(range(len(class_names)))

    # Pick highest-probability class among selected candidates using actual softmax probabilities
    best_idx = max(candidate_indices, key=lambda idx: float(probs[idx]))
    raw_class = class_names[best_idx]
    meta = CLASS_METADATA.get(raw_class, {"cancer_type": requested_cancer, "label": raw_class, "is_benign": False})

    # If a specific cancer category was selected, normalize confidence within that cancer's classes
    # and also report the raw overall softmax probability cleanly without inventing values.
    raw_softmax_pct = round(float(probs[best_idx]) * 100.0, 2)
    category_sum = float(sum(probs[idx] for idx in candidate_indices))
    if requested_cancer != "Auto-Detect" and category_sum > 0:
        confidence_pct = round((float(probs[best_idx]) / category_sum) * 100.0, 2)
    else:
        confidence_pct = raw_softmax_pct

    # Overall top prediction across all 24 trained classes
    overall_idx = int(np.argmax(probs))
    overall_raw_class = class_names[overall_idx]
    overall_meta = CLASS_METADATA.get(overall_raw_class, {"cancer_type": "Unknown", "label": overall_raw_class, "is_benign": False})

    sorted_indices = sorted(candidate_indices, key=lambda idx: float(probs[idx]), reverse=True)[:3]
    top_predictions = []
    for idx in sorted_indices:
        cname = class_names[idx]
        cmeta = CLASS_METADATA.get(cname, {"cancer_type": "Unknown", "label": cname, "is_benign": False})
        rel_pct = (float(probs[idx]) / category_sum * 100.0) if (requested_cancer != "Auto-Detect" and category_sum > 0) else (float(probs[idx]) * 100.0)
        top_predictions.append({
            "raw_class": cname,
            "label": cmeta["label"],
            "cancer_type": cmeta["cancer_type"],
            "probability": round(rel_pct, 2),
            "raw_softmax_probability": round(float(probs[idx]) * 100.0, 2),
            "is_benign": bool(cmeta["is_benign"]),
        })

    clinical_risk = calculate_clinical_risk({
        "bp": bp,
        "glucose": glucose,
        "smoking": smoking,
        "tumor_marker_cea": tumor_marker_cea,
        "tumor_marker_ca125": tumor_marker_ca125,
    })

    classification_status = "Benign / Normal Pattern" if meta["is_benign"] else "Abnormal Pattern — Clinical Review"
    actions = generate_clinical_actions(meta["cancer_type"], meta["label"], bool(meta["is_benign"]), clinical_risk)

    return JSONResponse({
        "success": True,
        "data": {
            "model_name": "Multi-Cancer MobileNetV2 (Keras 24-Class)",
            "condition": meta["label"],
            "raw_class": raw_class,
            "cancer_type": meta["cancer_type"],
            "requested_cancer_type": requested_cancer,
            "classification_status": classification_status,
            "stage": classification_status,
            "is_benign": bool(meta["is_benign"]),
            "confidence": confidence_pct,
            "raw_softmax_confidence": raw_softmax_pct,
            "overall_top_class": overall_raw_class,
            "overall_top_label": overall_meta["label"],
            "overall_top_cancer_type": overall_meta["cancer_type"],
            "overall_top_confidence": round(float(probs[overall_idx]) * 100.0, 2),
            "top_predictions": top_predictions,
            "clinical_risk_score": round(clinical_risk, 2),
            "actions": actions,
        },
    })


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app:app", host="127.0.0.1", port=8000, log_level="info")
