package render

import (
	"bytes"
	"fmt"
	"html/template"
	"time"

	"github.com/go-rod/rod"
	"github.com/go-rod/rod/lib/proto"
)

func RenderToPNG(htmlTemplate string, data map[string]string) ([]byte, error) {
	tmpl, err := template.New("social").Parse(htmlTemplate)
	if err != nil {
		return nil, fmt.Errorf("parse template: %w", err)
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, data); err != nil {
		return nil, fmt.Errorf("execute template: %w", err)
	}

	browser := rod.New().MustConnect()
	defer browser.MustClose()

	page := browser.MustPage()
	defer page.MustClose()

	page.MustSetDocumentContent(buf.String())
	page.MustWaitStable()
	time.Sleep(100 * time.Millisecond)

	img, err := page.Screenshot(true, &proto.PageCaptureScreenshot{
		Format: proto.PageCaptureScreenshotFormatPng,
	})
	if err != nil {
		return nil, fmt.Errorf("screenshot: %w", err)
	}

	return img, nil
}
