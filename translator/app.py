from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import ctranslate2
import transformers
from typing import List

app = FastAPI()

# NLLB uses FLORES-200 language codes, not simple "de"/"en"/"ru"/"uk"
LANG_CODES = {
    "de": "deu_Latn",
    "en": "eng_Latn",
    "ru": "rus_Cyrl",
    "uk": "ukr_Cyrl",
}

MODEL_PATH = "/models/nllb"
TOKENIZER_NAME = "facebook/nllb-200-distilled-600M"

print("Loading NLLB tokenizer and CT2 translator...")
tokenizer = transformers.AutoTokenizer.from_pretrained(TOKENIZER_NAME)
translator = ctranslate2.Translator(MODEL_PATH, device="cpu")
print("Ready.")


class TranslationRequest(BaseModel):
    # direction like "de-en", "de-ru", "de-uk" — kept for interface
    # compatibility with the rest of the app, but internally NLLB
    # translates directly, no pivot language involved
    direction: str
    sentences: List[str]


def parse_direction(direction: str) -> tuple[str, str]:
    parts = direction.split("-")
    if len(parts) != 2 or parts[0] not in LANG_CODES or parts[1] not in LANG_CODES:
        raise HTTPException(status_code=400, detail=f"Unsupported direction: {direction}")
    return LANG_CODES[parts[0]], LANG_CODES[parts[1]]


@app.post("/translate")
def translate(request: TranslationRequest):
    src_code, tgt_code = parse_direction(request.direction)

    tokenizer.src_lang = src_code

    tokenized = []
    for sentence in request.sentences:
        tokens = tokenizer.convert_ids_to_tokens(tokenizer.encode(sentence))
        tokenized.append(tokens)

    results = translator.translate_batch(
        tokenized,
        target_prefix=[[tgt_code]] * len(tokenized),
    )

    output = []
    for res in results:
        tokens = res.hypotheses[0]
        # Drop the target language token NLLB prepends to the output
        if tokens and tokens[0] == tgt_code:
            tokens = tokens[1:]
        text = tokenizer.decode(tokenizer.convert_tokens_to_ids(tokens))
        output.append(text)

    return {"translations": output}


@app.get("/health")
def health():
    return {"status": "ok"}
