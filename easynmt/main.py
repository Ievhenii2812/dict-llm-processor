# easynmt/main.py

from fastapi import FastAPI
from pydantic import BaseModel
from easynmt import EasyNMT

app = FastAPI()

# Загружаем модель один раз при старте
model = EasyNMT('opus-mt')


class TranslateRequest(BaseModel):
    text: str
    source_lang: str
    target_lang: str


class TranslateBatchRequest(BaseModel):
    texts: list[str]
    source_lang: str
    target_lang: str


class TranslateResponse(BaseModel):
    translation: str


class TranslateBatchResponse(BaseModel):
    translations: list[str]


@app.post("/translate", response_model=TranslateResponse)
def translate(request: TranslateRequest):
    result = model.translate(
        request.text,
        target_lang=request.target_lang,
        source_lang=request.source_lang,
    )
    return TranslateResponse(translation=result or "")


@app.post("/translate-batch", response_model=TranslateBatchResponse)
def translate_batch(request: TranslateBatchRequest):
    if not request.texts:
        return TranslateBatchResponse(translations=[])

    results = model.translate(
        request.texts,
        target_lang=request.target_lang,
        source_lang=request.source_lang,
        batch_size=16,
    )

    if isinstance(results, str):
        results = [results]

    return TranslateBatchResponse(translations=results or [])


@app.get("/health")
def health():
    return {"status": "ok"}
