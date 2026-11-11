from __future__ import annotations

import sqlite3
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / "data" / "saji_pos.sqlite3"
BACKUPS = ROOT / "data" / "backups"


def backup(label: str = "python-manual") -> Path:
    BACKUPS.mkdir(parents=True, exist_ok=True)
    target = BACKUPS / f"backup_{label}_{datetime.now():%Y%m%d_%H%M%S}.sqlite3"
    source = sqlite3.connect(DB)
    try:
        source.execute("PRAGMA wal_checkpoint(FULL)")
        destination = sqlite3.connect(target)
        try:
            source.backup(destination)
        finally:
            destination.close()
    finally:
        source.close()
    return target


if __name__ == "__main__":
    if not DB.exists():
        raise SystemExit(f"Database not found yet: {DB}")
    print(backup())
