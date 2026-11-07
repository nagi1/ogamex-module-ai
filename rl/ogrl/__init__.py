"""Training side of the OGameX AI economy policy. See ../README.md and ../../plan/rl/."""

import os

# CUDA numbers devices fastest-first unless told otherwise; nvidia-smi numbers them by PCI bus. Pin the bus order before
# torch touches CUDA, so `cuda:1` is the RTX 4070 Ti SUPER here, as nvidia-smi shows it, and GPU 0 stays free.
os.environ.setdefault("CUDA_DEVICE_ORDER", "PCI_BUS_ID")

MAX_CANDIDATES = 32


def default_device() -> str:
    """cuda:1 when there are two GPUs (the training card; GPU 0 is not used), cuda:0 when one, else the CPU."""
    import torch

    if not torch.cuda.is_available():
        return "cpu"
    return "cuda:1" if torch.cuda.device_count() > 1 else "cuda:0"
