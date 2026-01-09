' VBScript для запуска бэкенда в фоновом режиме на Windows
' Создает скрытое окно для Node.js процесса

Set WshShell = CreateObject("WScript.Shell")
WshShell.CurrentDirectory = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
WshShell.Run "node dist/server.js", 0, False
Set WshShell = Nothing
