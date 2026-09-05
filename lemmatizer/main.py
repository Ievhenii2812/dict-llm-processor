# lemmatizer/main.py

from fastapi import FastAPI
from pydantic import BaseModel
import spacy
from compound_split import char_split

app = FastAPI()

# Загружаем модель один раз при старте
nlp = spacy.load("de_core_news_lg")


class WordRequest(BaseModel):
    word: str


class SentenceRequest(BaseModel):
    sentence: str


class WordResponse(BaseModel):
    lemma: str
    is_proper_noun: bool


class SentenceResponse(BaseModel):
    lemmas: list[str]


class CompoundSplitRequest(BaseModel):
    word: str


class CompoundSplitResponse(BaseModel):
    is_compound: bool
    segments: list[str]  # e.g. ["Lager", "Halle"] or ["Ehrlichkeit"] if not split


@app.post("/lemmatize", response_model=WordResponse)
def lemmatize(request: WordRequest):
    doc = nlp(request.word)

    if not doc:
        return WordResponse(lemma=request.word, is_proper_noun=False)

    token = doc[0]

    lemma = token.lemma_

    # spaCy иногда возвращает лемму в нижнем регистре для существительных
    # Восстанавливаем заглавную букву если оригинал был с заглавной
    if request.word[0].isupper() and lemma and not lemma[0].isupper():
        lemma = lemma[0].upper() + lemma[1:]

    is_proper_noun = token.pos_ == "PROPN"

    return WordResponse(lemma=lemma or request.word, is_proper_noun=is_proper_noun)


@app.post("/lemmatize-sentence", response_model=SentenceResponse)
def lemmatize_sentence(request: SentenceRequest):
    doc = nlp(request.sentence)

    lemmas = []

    for token in doc:
        # Пропускаем пунктуацию, пробелы, стоп-слова
        if token.is_punct or token.is_space:
            continue

        lemma = token.lemma_

        if not lemma or len(lemma) < 2:
            continue

        # Восстанавливаем регистр для существительных
        if token.text[0].isupper() and lemma and not lemma[0].isupper():
            lemma = lemma[0].upper() + lemma[1:]

        lemmas.append(lemma)

    return SentenceResponse(lemmas=list(set(lemmas)))


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/compound-split", response_model=CompoundSplitResponse)
def compound_split(request: CompoundSplitRequest):
    try:
        # char_split.split_compound returns ranked list of (score, part1, part2)
        results = char_split.split_compound(request.word)
    except Exception:
        return CompoundSplitResponse(is_compound=False, segments=[request.word])

    if not results:
        return CompoundSplitResponse(is_compound=False, segments=[request.word])

    # Take the highest-scoring split. Negative/low scores mean the split
    # is unlikely to be a real compound — CharSplit still returns SOMETHING
    # even for non-compounds, so a score threshold matters.
    best_score, part1, part2 = results[0]

    if best_score <= 0:
        return CompoundSplitResponse(is_compound=False, segments=[request.word])

    return CompoundSplitResponse(is_compound=True, segments=[part1, part2])