// Package applog provides simple file-based logging with rotation for debugging.
// Logs go to logs/app.log with rotation at 5MB. Old log becomes logs/app.log.1.
package applog

import (
	"fmt"
	"os"
	"sync"
	"time"
)

const (
	logFile    = "logs/app.log"
	oldLogFile = "logs/app.log.1"
	maxSize    = 5 * 1024 * 1024 // 5MB
)

var (
	mu sync.Mutex
)

func ensureDir() {
	os.MkdirAll("logs", 0755)
}

func rotate() {
	info, err := os.Stat(logFile)
	if err != nil || info.Size() < maxSize {
		return
	}
	os.Remove(oldLogFile)
	os.Rename(logFile, oldLogFile)
}

func write(level, msg string) {
	mu.Lock()
	defer mu.Unlock()
	ensureDir()
	rotate()

	f, err := os.OpenFile(logFile, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	if err != nil {
		return
	}
	defer f.Close()

	ts := time.Now().Format("2006-01-02 15:04:05")
	fmt.Fprintf(f, "[%s] %s: %s\n", ts, level, msg)
}

// Info logs an informational message.
func Info(format string, args ...any) {
	write("INFO", fmt.Sprintf(format, args...))
}

// Error logs an error message.
func Error(format string, args ...any) {
	write("ERROR", fmt.Sprintf(format, args...))
}

// Debug logs a debug message.
func Debug(format string, args ...any) {
	write("DEBUG", fmt.Sprintf(format, args...))
}
