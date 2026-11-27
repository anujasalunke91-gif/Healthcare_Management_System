# MedCore Health

## Hosted demo

Open `/portal/`, create an account, and confirm your email if prompted. The account starts with an isolated workspace and fictional profiles, a confirmed appointment, and a demo bill. Use the **Demo role** menu to switch between Patient, Doctor, and Administrator.

1. In Patient, request a visit with the primary demo doctor and open your profile.
2. In Doctor, confirm the visit, run the built-in synthetic scan, publish a review, and save a fictional prescription.
3. In Patient, view the review and download a report or receipt. In Administrator, manage fictional profiles and mark demo bills as paid.

**Synthetic data only.** Do not enter identifiable patient information or upload real scans. The app does not process payments, and the model's scores are not diagnoses, clinical risks, or treatment recommendations.

Netlify publishes only the landing page, portal, and public assets. Netlify Identity handles accounts, Netlify Database stores the synthetic records, and private Netlify Blobs store normalized demo images. The build converts the bundled 24-class Keras model to ONNX for CPU inference on the Netlify Server; no external training dataset, Python runtime service, or AI key is needed. `npm test` checks the local portal API workflows with an isolated test database.

## Original PHP application

The original PHP/MySQL site and FastAPI service are retained under [`healthcare_platform/`](healthcare_platform/). See its [local setup instructions](healthcare_platform/README.md). The hosted demo screens do not execute the original PHP endpoints or import the original MySQL seed accounts.
