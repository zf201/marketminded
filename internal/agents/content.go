package agents

import (
	"context"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/types"
)

type ContentAgent struct {
	ai    types.AIClient
	model func() string
}

func NewContentAgent(ai types.AIClient, model func() string) *ContentAgent {
	return &ContentAgent{ai: ai, model: model}
}

type PillarInput struct {
	Topic      string
	Profile    string   // serialized profile sections
	ContentLog []string
}

func (a *ContentAgent) WritePillar(ctx context.Context, input PillarInput) (string, error) {
	return a.ai.Complete(ctx, a.model(), a.pillarMessages(input))
}

func (a *ContentAgent) WritePillarStream(ctx context.Context, input PillarInput, fn types.StreamFunc) (string, error) {
	return a.ai.Stream(ctx, a.model(), a.pillarMessages(input), fn)
}

func (a *ContentAgent) pillarMessages(input PillarInput) []types.Message {
	contentLog := "No previous content."
	if len(input.ContentLog) > 0 {
		contentLog = strings.Join(input.ContentLog, "\n")
	}

	return []types.Message{
		{
			Role: "system",
			Content: fmt.Sprintf(`You are an expert blog writer. Write a comprehensive, engaging blog post on the given topic.

Client Profile:
%s

Guidelines:
- Write in the brand's voice and tone as described in the profile
- Use markdown formatting
- Include a compelling introduction with a hook
- Break into clear sections with headers
- Include actionable takeaways
- End with a strong conclusion
- Aim for 1200-1800 words
- Do NOT repeat themes from the content log below`, input.Profile),
		},
		{
			Role: "user",
			Content: fmt.Sprintf("Topic: %s\n\nPrevious content (for continuity, don't repeat):\n%s\n\nWrite the blog post.",
				input.Topic, contentLog),
		},
	}
}

type SocialInput struct {
	PillarContent string
	Platform      string
	Profile       string // serialized profile sections
	TemplateSlots string
}

func (a *ContentAgent) WriteSocialPost(ctx context.Context, input SocialInput) (string, error) {
	return a.ai.Complete(ctx, a.model(), a.socialMessages(input))
}

func (a *ContentAgent) WriteSocialPostStream(ctx context.Context, input SocialInput, fn types.StreamFunc) (string, error) {
	return a.ai.Stream(ctx, a.model(), a.socialMessages(input), fn)
}

func (a *ContentAgent) socialMessages(input SocialInput) []types.Message {
	platformGuide := platformGuidelines(input.Platform)

	return []types.Message{
		{
			Role: "system",
			Content: fmt.Sprintf(`You are a social media content expert. Repurpose the pillar blog post into a %s post.

Client Profile:
%s

Platform guidelines: %s

If template slots are provided, output JSON with the slot values (Title, Body, ImageURL). Otherwise output the post text directly.`, input.Platform, input.Profile, platformGuide),
		},
		{
			Role:    "user",
			Content: fmt.Sprintf("Pillar content:\n\n%s\n\nTemplate slots: %s\n\nWrite the %s post.", input.PillarContent, input.TemplateSlots, input.Platform),
		},
	}
}

func platformGuidelines(platform string) string {
	switch platform {
	case "linkedin":
		return "Professional tone. Hook in first line. Use line breaks for readability. 1300 char max. Include a CTA."
	case "instagram":
		return "Engaging, visual language. Hook in first line. Use emojis sparingly. Include relevant hashtags. 2200 char max."
	case "facebook":
		return "Conversational. Hook in first line. Encourage engagement/comments. 500 char ideal."
	default:
		return "Write an engaging post appropriate for the platform."
	}
}
