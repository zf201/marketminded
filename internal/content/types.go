package content

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
)

type ContentType struct {
	Platform    string
	Format      string
	DisplayName string
	PromptFile  string
	ToolName    string
	Tool        ai.Tool
}

var Registry = map[string]ContentType{}

func init() {
	register("blog", "post", "Blog Post", "blog_post", "write_blog_post",
		`{"type":"object","properties":{"title":{"type":"string","description":"Blog post title"},"body":{"type":"string","description":"Full blog post in markdown"}},"required":["title","body"]}`)

	register("linkedin", "post", "LinkedIn Post", "linkedin_post", "write_linkedin_post",
		`{"type":"object","properties":{"caption":{"type":"string","description":"Post caption. Hook in first line. 1200-1500 chars."},"hashtags":{"type":"string","description":"Hashtags, space-separated. 3-5 max."}},"required":["caption"]}`)

	register("linkedin", "carousel", "LinkedIn Carousel", "linkedin_carousel", "write_linkedin_carousel",
		`{"type":"object","properties":{"slides":{"type":"array","items":{"type":"object","properties":{"title":{"type":"string"},"body":{"type":"string"}},"required":["title","body"]},"description":"Carousel slides. Slide 1 is hook. Last slide is summary + CTA."},"caption":{"type":"string","description":"Post caption."}},"required":["slides","caption"]}`)

	register("instagram", "post", "Instagram Post", "instagram_post", "write_instagram_post",
		`{"type":"object","properties":{"caption":{"type":"string","description":"Post caption. Hook first line. Under 2200 chars."},"hashtags":{"type":"string","description":"Hashtags, space-separated. Up to 15."},"image_instructions":{"type":"string","description":"Instructions for the visual/image to pair with this post."}},"required":["caption"]}`)

	register("instagram", "reel", "Instagram Reel", "instagram_reel", "write_instagram_reel",
		`{"type":"object","properties":{"hook":{"type":"string","description":"First 1-2 seconds. Pattern interrupt or bold claim."},"setup":{"type":"string","description":"2-5 seconds. Context."},"value":{"type":"string","description":"5-25 seconds. The actual content."},"cta":{"type":"string","description":"Last 5 seconds. Follow/comment/share CTA."},"caption":{"type":"string","description":"Post caption with hashtags."}},"required":["hook","setup","value","cta","caption"]}`)

	register("instagram", "carousel", "Instagram Carousel", "instagram_carousel", "write_instagram_carousel",
		`{"type":"object","properties":{"slides":{"type":"array","items":{"type":"object","properties":{"text":{"type":"string"}},"required":["text"]},"description":"Carousel slides. One point per slide."},"caption":{"type":"string","description":"Post caption."},"hashtags":{"type":"string","description":"Hashtags."}},"required":["slides","caption"]}`)

	register("x", "post", "X Post", "x_post", "write_x_post",
		`{"type":"object","properties":{"text":{"type":"string","description":"Single tweet. Under 280 chars."}},"required":["text"]}`)

	register("x", "thread", "X Thread", "x_thread", "write_x_thread",
		`{"type":"object","properties":{"tweets":{"type":"array","items":{"type":"string"},"description":"Array of tweets. First is hook. Last is CTA. Each under 280 chars."}},"required":["tweets"]}`)

	register("youtube", "script", "YouTube Script", "youtube_script", "write_youtube_script",
		`{"type":"object","properties":{"title":{"type":"string","description":"Video title"},"sections":{"type":"array","items":{"type":"object","properties":{"timestamp":{"type":"string"},"heading":{"type":"string"},"content":{"type":"string"},"notes":{"type":"string"}},"required":["heading","content"]},"description":"Script sections with optional timestamps and delivery notes."}},"required":["title","sections"]}`)

	register("youtube", "short", "YouTube Short", "youtube_short", "write_youtube_short",
		`{"type":"object","properties":{"hook":{"type":"string","description":"First 1-2 seconds."},"content":{"type":"string","description":"Main content. Under 60 seconds total."},"cta":{"type":"string","description":"Follow/subscribe CTA."}},"required":["hook","content","cta"]}`)

	register("facebook", "post", "Facebook Post", "facebook_post", "write_facebook_post",
		`{"type":"object","properties":{"caption":{"type":"string","description":"Post text. Conversational. 500 chars ideal."}},"required":["caption"]}`)

	register("tiktok", "video", "TikTok Video", "tiktok_video", "write_tiktok_video",
		`{"type":"object","properties":{"hook":{"type":"string","description":"First 1-2 seconds."},"content":{"type":"string","description":"Main content."},"cta":{"type":"string","description":"Follow CTA."},"caption":{"type":"string","description":"Post caption."}},"required":["hook","content","cta","caption"]}`)
}

func register(platform, format, displayName, promptFile, toolName, paramsJSON string) {
	// Inject "instructions" field into every tool's properties
	paramsJSON = strings.Replace(paramsJSON, `},"required"`,
		`,"instructions":{"type":"string","description":"Production notes: image/visual guidance, design direction, or any instructions for the person creating this piece."}},"required"`, 1)

	key := platform + "_" + format
	Registry[key] = ContentType{
		Platform:    platform,
		Format:      format,
		DisplayName: displayName,
		PromptFile:  promptFile,
		ToolName:    toolName,
		Tool: ai.Tool{
			Type: "function",
			Function: ai.ToolFunction{
				Name:        toolName,
				Description: "Write a " + displayName + ". Provide the structured content. Include instructions field with production notes (image guidance, visual direction, etc.).",
				Parameters:  json.RawMessage(paramsJSON),
			},
		},
	}
}

func LookupType(platform, format string) (ContentType, bool) {
	ct, ok := Registry[platform+"_"+format]
	return ct, ok
}

// LoadPrompt reads the prompt file for a content type from the prompts/types/ directory.
func LoadPrompt(promptFile string) (string, error) {
	path := filepath.Join("prompts", "types", promptFile+".md")
	data, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	return string(data), nil
}

// IsWriteTool returns true if the tool name is a content write tool.
func IsWriteTool(toolName string) bool {
	for _, ct := range Registry {
		if ct.ToolName == toolName {
			return true
		}
	}
	return false
}
