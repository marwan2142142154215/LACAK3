@echo off
setlocal
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0Start-SMB-Server.ps1" %*
exit /b %ERRORLEVEL%
