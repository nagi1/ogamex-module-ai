# Module-owned headless CBRKit driver. Build from this directory:
#   docker build -t ogamex-ai-cognition-cbrkit:1.6.0 .
FROM python:3.13-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PYTHONPATH=/driver

WORKDIR /driver

COPY requirements.txt ./
RUN pip install --no-cache-dir --disable-pip-version-check -r requirements.txt

COPY retriever.py eval_retriever.py ./

# cbrkit 1.6.0 builds its API settings and loads callables while importing
# `cbrkit.api`, but `cbrkit serve` only assigns CBRKIT_RETRIEVER afterwards.
# The documented --retriever/--search-path flags are therefore inert, so the
# retriever is selected through the environment the settings actually read.
ENV CBRKIT_RETRIEVER=retriever:ogame_experience_retriever

EXPOSE 8080

# The image installs no provider or embedding extra, so the served process has no
# library that could call a generative model.
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/openapi.json', timeout=3)"

CMD ["cbrkit", "serve", "--host", "0.0.0.0", "--port", "8080"]
