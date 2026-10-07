from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
from pathlib import Path


def find_project_root() -> Path | None:
    executable = Path(sys.executable if getattr(sys, "frozen", False) else __file__).resolve()
    for candidate in (executable.parent, *executable.parents):
        if (candidate / "compose.yaml").is_file() and (
            candidate / "smb-server-launcher" / "Start-SMB-Server.ps1"
        ).is_file():
            return candidate
    return None


def main() -> int:
    parser = argparse.ArgumentParser(
        description="SMB Server launcher: start, stop, or inspect the local SMB services."
    )
    actions = parser.add_mutually_exclusive_group()
    actions.add_argument("--stop", action="store_true", help="stop SMB containers safely")
    actions.add_argument("--doctor", action="store_true", help="check SMB service health")
    args = parser.parse_args()

    root = find_project_root()
    if root is None:
        print(
            "Folder project SMB tidak ditemukan. Simpan SMB Server.exe di "
            "<folder-project>/smb-server-launcher/ bersama compose.yaml dan .env.",
            file=sys.stderr,
        )
        return 2

    powershell = shutil.which("powershell.exe") or shutil.which("powershell")
    if powershell is None:
        print("Windows PowerShell tidak ditemukan.", file=sys.stderr)
        return 2

    launcher_dir = root / "smb-server-launcher"
    script = launcher_dir / ("SMB-Doctor.ps1" if args.doctor else "Start-SMB-Server.ps1")
    command = [
        powershell,
        "-NoLogo",
        "-NoProfile",
        "-ExecutionPolicy",
        "Bypass",
        "-File",
        str(script),
    ]
    if args.stop:
        command.append("-Stop")

    print(f"SMB Server: menjalankan {script.name}", flush=True)
    try:
        result = subprocess.run(command, cwd=root, check=False)
    except OSError as error:
        print(f"Tidak dapat menjalankan PowerShell: {error}", file=sys.stderr)
        return 1

    return result.returncode


if __name__ == "__main__":
    raise SystemExit(main())
