FROM python:3.11-slim

WORKDIR /app

RUN apt-get update && apt-get install -y build-essential && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Temporary CPU torch only for model conversion
RUN pip install --no-cache-dir --index-url https://download.pytorch.org/whl/cpu torch

# Convert NLLB-200 distilled 600M to CTranslate2 format
# Single model handles ALL language pairs (deu->eng, deu->rus, deu->ukr)
# — no pivot through English needed, unlike the old Helsinki setup
RUN mkdir -p /models/nllb && \
    python3 -c "import ctranslate2; \
    print('Converting NLLB-200-distilled-600M...'); \
    ctranslate2.converters.TransformersConverter('facebook/nllb-200-distilled-600M').convert('/models/nllb', force=True); \
    print('Done.');"

# Drop torch — container stays small, CT2 does not need it at runtime
RUN pip uninstall torch -y

COPY app.py .
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
