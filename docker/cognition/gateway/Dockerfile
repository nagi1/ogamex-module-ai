# social-gateway: reverse proxy, batch fan-out and answer cache in front of the cognition engines.
FROM python:3.13-slim

RUN pip install --no-cache-dir aiohttp==3.11.18

WORKDIR /app
COPY server.py .

EXPOSE 8099
HEALTHCHECK --interval=15s --timeout=5s --start-period=5s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8099/health', timeout=3)"
CMD ["python", "server.py"]
