FROM python:3.11-slim

WORKDIR /app

# Жёстко заставляем все библиотеки использовать папку /cache
ENV HF_HOME=/cache/huggingface
ENV TORCH_HOME=/cache/torch
ENV NLTK_DATA=/cache/nltk_data

# Для fasttext обязательно нужны g++ и make
RUN apt-get update && apt-get install -y \
    gcc \
    g++ \
    make \
    && rm -rf /var/lib/apt/lists/*

# 1. Сначала ставим чистый PyTorch для CPU из официального репозитория
RUN pip install --no-cache-dir --upgrade pip && \
    pip install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu

# 2. Теперь ставим easynmt и остальное. Pip увидит, что torch уже есть,
# и НЕ будет качать гигабайты CUDA-зависимостей.
RUN CFLAGS="-std=c++17" CXXFLAGS="-std=c++17" pip install --no-cache-dir \
    easynmt \
    fastapi \
    uvicorn

# 3. скачиваем punkt_tab прямо в образ
RUN python -c "import nltk; nltk.download('punkt_tab')"

COPY main.py .

EXPOSE 8001

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001"]