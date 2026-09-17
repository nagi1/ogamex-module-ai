# Module-owned headless PsychSim driver. Build from this directory:
#   docker build -t ogamex-ai-cognition-psychsim:1.0.0 .
#
# PsychSim's core is pure Python + stdlib (the spike proved forward_planning.py
# runs headless in this exact image with no third-party import), so the image
# carries only the pinned source and a stdlib HTTP server — no numpy/scipy/Qt.
FROM python:3.13-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PYTHONPATH=/psychsim

WORKDIR /driver

# Pin the upstream source: MIT, python_requires >=3.6, no install_requires.
ADD https://github.com/usc-psychsim/psychsim/archive/56ef6e95a9f22dab3a4c216f34494363c78b621f.tar.gz /tmp/psychsim.tar.gz
RUN tar -xzf /tmp/psychsim.tar.gz -C /tmp \
    && mv /tmp/psychsim-56ef6e95a9f22dab3a4c216f34494363c78b621f /psychsim \
    && rm /tmp/psychsim.tar.gz

COPY server.py ./

# The served process has no provider or embedding library and no Qt UI, so it
# cannot call a model and cannot open a window.
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/health', timeout=3)"

EXPOSE 8080

CMD ["python", "server.py"]
