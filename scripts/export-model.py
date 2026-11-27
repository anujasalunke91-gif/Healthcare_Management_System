from pathlib import Path
from shutil import copyfile
import os

os.environ.setdefault("CUDA_VISIBLE_DEVICES", "-1")
os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")
os.environ.setdefault("TF_NUM_INTEROP_THREADS", "1")
os.environ.setdefault("TF_NUM_INTRAOP_THREADS", "1")

import onnx
import tensorflow as tf
import tf2onnx


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "healthcare_platform/ai_service/models/Multi_Cancer_Model"
ARTIFACTS = ROOT / "netlify/server/artifacts"


def convert_model():
    model = tf.keras.models.load_model(SOURCE / "multi_cancer_model.keras", compile=False)
    spec = (tf.TensorSpec((1, 224, 224, 3), tf.float32, name="scan"),)

    @tf.function(input_signature=spec)
    def predict(scan):
        return model(scan, training=False)

    exported, _ = tf2onnx.convert.from_function(predict, input_signature=spec, opset=15)
    onnx.checker.check_model(exported)
    return model, exported


if __name__ == "__main__":
    labels = (SOURCE / "class_names.txt").read_text().splitlines()
    model, exported = convert_model()
    if len(labels) != model.output_shape[-1] or not all(labels):
        raise ValueError("Class names must match the model output")
    ARTIFACTS.mkdir(parents=True, exist_ok=True)
    onnx.save(exported, ARTIFACTS / "multi-cancer.onnx")
    copyfile(SOURCE / "class_names.txt", ARTIFACTS / "class_names.txt")
