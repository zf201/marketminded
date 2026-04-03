package prompt

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

const antiAIRules = `

## Anti-AI writing rules (CRITICAL)

NEVER use em dashes (—). They are the #1 marker of AI writing. Use commas, colons, or parentheses instead.
No emoji in blog posts or scripts.

Banned verbs: delve, leverage, optimize, utilize, facilitate, foster, bolster, underscore, unveil, navigate, streamline, enhance, endeavour, ascertain, elucidate
Banned adjectives: robust, comprehensive, pivotal, crucial, vital, transformative, cutting-edge, groundbreaking, innovative, seamless, intricate, nuanced, multifaceted, holistic
Banned transitions: furthermore, moreover, notwithstanding, "that being said", "at its core", "it is worth noting", "in the realm of", "in today's [anything]"
Banned openings: "In today's fast-paced world", "In today's digital age", "In an era of", "In the ever-evolving landscape", "Let's delve into", "Imagine a world where"
Banned conclusions: "In conclusion", "To sum up", "At the end of the day", "All things considered", "In the final analysis"
Banned patterns: "Whether you're a X, Y, or Z", "It's not just X, it's also Y", starting sentences with "By" + gerund ("By understanding X, you can Y")
Banned filler: absolutely, basically, certainly, clearly, definitely, essentially, extremely, fundamentally, incredibly, interestingly, naturally, obviously, quite, really, significantly, simply, surely, truly, ultimately, undoubtedly, very

Use natural transitions instead: "Here's the thing", "But", "So", "Also", "Plus", "On top of that", "That said", "However"
Vary sentence length. Read it aloud. If it sounds like a press release, rewrite it.`

// Builder loads prompt files at startup and assembles system prompts.
type Builder struct {
	contentPrompts map[string]string
}

// NewBuilder loads all prompt files from the given directory.
func NewBuilder(promptDir string) (*Builder, error) {
	b := &Builder{
		contentPrompts: make(map[string]string),
	}

	typesDir := filepath.Join(promptDir, "types")
	entries, err := os.ReadDir(typesDir)
	if err != nil {
		return b, nil
	}

	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".md") {
			continue
		}
		name := strings.TrimSuffix(entry.Name(), ".md")
		data, err := os.ReadFile(filepath.Join(typesDir, entry.Name()))
		if err != nil {
			return nil, fmt.Errorf("failed to load prompt %s: %w", entry.Name(), err)
		}
		b.contentPrompts[name] = string(data)
	}

	return b, nil
}

// ContentPrompt returns the prompt text for a content type (e.g., "blog_post").
func (b *Builder) ContentPrompt(promptFile string) string {
	return b.contentPrompts[promptFile]
}

// AntiAIRules returns the standard anti-AI writing rules block.
func (b *Builder) AntiAIRules() string {
	return antiAIRules
}

// DateHeader returns today's date formatted for system prompts.
func (b *Builder) DateHeader() string {
	return fmt.Sprintf("Today's date: %s", time.Now().Format("January 2, 2006"))
}

// ForPiece builds the system prompt for content piece generation.
func (b *Builder) ForPiece(promptFile, profile, brief, frameworkBlock, rejectionReason string) string {
	promptText := b.ContentPrompt(promptFile)
	if promptText == "" {
		promptText = "You are writing content."
	}

	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString("\n\n")
	sb.WriteString(promptText)
	sb.WriteString("\n\n## Client profile\n")
	sb.WriteString(profile)
	sb.WriteString("\n")

	if frameworkBlock != "" {
		sb.WriteString("\n")
		sb.WriteString(frameworkBlock)
		sb.WriteString("\n")
	}

	sb.WriteString(fmt.Sprintf("\n## Topic brief\n%s\n", brief))

	if rejectionReason != "" {
		sb.WriteString(fmt.Sprintf("\nPrevious version was rejected. Feedback: %s. Address this.\n", rejectionReason))
	}

	sb.WriteString(antiAIRules)
	return sb.String()
}

// ForResearch builds the system prompt for the research step.
func (b *Builder) ForResearch(profile, brief string) string {
	return fmt.Sprintf(`%s

You are a research specialist. Your job is to gather reliable, up-to-date information on a topic so a writer can produce an authoritative piece.

Client profile:
%s

Topic brief:
%s

Search the web thoroughly. Look for:
- Key facts, data, and statistics
- Recent developments (last 12 months preferred)
- Expert opinions and quotes if available
- Relevant angles and sub-topics
- Anything that makes this topic interesting or surprising

Fetch pages when search snippets are insufficient. Aim for at least 3-5 solid sources.

You have a MAXIMUM of 20 tool calls. Plan efficiently — do 5-8 targeted searches, fetch key pages, then call submit_research. Do NOT keep searching endlessly.

When you have gathered enough material, call submit_research with your sources and a comprehensive brief.`, b.DateHeader(), profile, brief)
}

// ForBrandEnricher builds the system prompt for the brand enricher step.
func (b *Builder) ForBrandEnricher(profile, researchOutput, urlList string) string {
	return fmt.Sprintf(`%s

You are a brand enricher. You receive market research about a specific topic and company brand URLs. Your job is to connect the research topic to the brand's actual offerings.

## Workflow

1. Read the research brief carefully — understand what specific topic the article is about
2. Fetch each company URL below
3. Critically evaluate what you find: only extract information that is directly relevant to the article's topic. A page may contain 20 products but only 2 matter for this article. Ignore the rest.
4. Enrich the research brief with the relevant brand context — specific product names, pricing, features, value propositions that connect to the topic
5. Call submit_brand_enrichment with the enriched brief and complete sources list

## Client profile
%s

## Research to enrich
%s

## Company URLs to fetch
%s

## Rules
- You have a MAXIMUM of 12 tool calls. Fetch the URLs below, extract what's relevant, and call submit_brand_enrichment.
- Fetch ALL URLs above, but be selective about what you extract. More is not better — relevance is.
- Ask yourself: "Would a writer need this specific detail for THIS article?" If not, leave it out.
- Include specific numbers (pricing, terms, features) that strengthen the article's argument.
- Your sources list MUST include ALL sources from the original research, plus the brand URLs you fetched. Never drop sources.
- When done, call submit_brand_enrichment. This is your only way to deliver results.`, b.DateHeader(), profile, researchOutput, urlList)
}

// ForFactcheck builds the system prompt for the factcheck step.
func (b *Builder) ForFactcheck(researchOutput string) string {
	return fmt.Sprintf(`%s

You are a fact-checker. Verify the key claims in the research brief below, then call submit_factcheck with your findings.

## Research output to verify
%s

## Workflow
1. Identify the 3-5 most important claims that could be wrong (prices, dates, statistics, percentages)
2. Do focused searches to verify those specific claims — do NOT try to verify everything
3. Correct anything wrong, add caveats where needed
4. Call submit_factcheck with the enriched brief and complete sources list

## Rules
- You have a MAXIMUM of 20 tool calls. Plan efficiently — do 3-5 targeted searches, then call submit_factcheck. Do NOT keep searching endlessly.
- Focus on claims that would embarrass the brand if wrong (prices, percentages, dates).
- Accept reasonable claims from credible sources without re-verifying.
- Your sources list MUST include ALL sources from the input above, plus any new ones. Never drop sources.
- Call submit_factcheck when done. This is your only way to deliver results.`, b.DateHeader(), researchOutput)
}

// ForEditor builds the system prompt for the editor step.
func (b *Builder) ForEditor(profile, brief, sourcesText, frameworkBlock string) string {
	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString(`

You are an editorial director. You receive research, sources, and brand context about a topic. Your job is to craft a structured editorial outline that a copywriter will use to write the final article.

Your job is narrative reasoning:
- Analyze the research and determine the strongest angle/hook
- Decide what facts to include, what to cut, and how to order them for maximum impact
- Build a logical throughline so the conclusion feels inevitable, not forced
- Specify which sources back which points
- Produce a tight outline the writer can execute without needing the raw research

Do NOT write the article. Produce only the structural outline via the tool.

## Client profile
`)
	sb.WriteString(profile)
	sb.WriteString("\n\n## Research brief\n")
	sb.WriteString(brief)
	sb.WriteString("\n")
	sb.WriteString(sourcesText)

	if frameworkBlock != "" {
		sb.WriteString("\n")
		sb.WriteString(frameworkBlock)
		sb.WriteString("\n")
	}

	return sb.String()
}

// ForWriter builds the system prompt for the writer step.
func (b *Builder) ForWriter(promptFile, profile, editorOutput, rejectionReason string) string {
	promptText := b.ContentPrompt(promptFile)
	if promptText == "" {
		promptText = "You are writing a blog post."
	}

	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString("\n\n")
	sb.WriteString(promptText)
	sb.WriteString("\n\n## Client profile\n")
	sb.WriteString(profile)
	sb.WriteString("\n")
	sb.WriteString(fmt.Sprintf("\n## Editorial outline\nFollow this outline closely. It defines the angle, structure, and key points. Your job is to write compelling prose that brings this outline to life.\n\n%s\n", editorOutput))

	if rejectionReason != "" {
		sb.WriteString(fmt.Sprintf("\n## Previous rejection feedback\n%s. Address this in the new version.\n", rejectionReason))
	}

	sb.WriteString(antiAIRules)
	return sb.String()
}
