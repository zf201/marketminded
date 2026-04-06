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

When you have gathered enough material, call submit_research with your sources and a comprehensive brief.

CRITICAL: Every response MUST include a tool call. You may think/reason, but you must ALWAYS also call a tool in the same response. A response with only text and no tool call is treated as a failure. When done researching, put all your findings directly into the submit_research tool call arguments.`, b.DateHeader(), profile, brief)
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
- Fetch ALL URLs above, but be selective about what you extract. More is not better — relevance is.
- Ask yourself: "Would a writer need this specific detail for THIS article?" If not, leave it out.
- Include specific numbers (pricing, terms, features) that strengthen the article's argument.
- Your sources list MUST include ALL sources from the original research, plus the brand URLs you fetched. Never drop sources.

## CRITICAL: You MUST use tool calls
Every response MUST include a tool call. You may think/reason, but you must ALWAYS also call a tool in the same response. A response with only text and no tool call is treated as a failure. After fetching all URLs, call submit_brand_enrichment IMMEDIATELY. Put all your analysis directly into the submit_brand_enrichment tool call arguments.`, b.DateHeader(), profile, researchOutput, urlList)
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
- Focus on claims that would embarrass the brand if wrong (prices, percentages, dates).
- Accept reasonable claims from credible sources without re-verifying.
- Your sources list MUST include ALL sources from the input above, plus any new ones. Never drop sources.

## CRITICAL: You MUST use tool calls
Every response MUST include a tool call. You may think/reason, but you must ALWAYS also call a tool in the same response. A response with only text and no tool call is treated as a failure. After verifying claims, call submit_factcheck IMMEDIATELY. Put all your findings directly into the submit_factcheck tool call arguments.`, b.DateHeader(), researchOutput)
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

func (b *Builder) ForTopicExplore(profile, blogContent, homepageContent, rejectedTopics, approvedTopics, instructions string) string {
	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString(`

You are a sharp editorial strategist who finds blog topics worth reading — real topics that make people stop scrolling.

## What makes a good topic
- It connects to something happening RIGHT NOW — a news event, industry shift, viral discussion, new regulation, a controversy, a trend
- It has a specific angle that only THIS brand can credibly write about
- A reader would forward it to a colleague or share it because it says something useful or surprising
- It teaches something, challenges a common belief, or reveals a hidden pattern

## What makes a BAD topic (avoid these)
- Generic "Ultimate Guide to X" or "Top 10 Tips for Y" — these are content mill topics
- Anything that reads like a rephrased keyword search — "What is [term]?" or "Benefits of [thing]"
- Topics disconnected from current events or the real world — timeless-sounding but actually just bland
- Topics where the brand connection is forced or purely self-promotional

## Your process
1. Review the brand profile to understand their niche, audience, and expertise
2. Search the web for CURRENT events, news, trends, discussions, controversies, and developments in the brand's space — use today's date as reference
3. Search for what the audience is actually talking about, struggling with, or debating right now
4. If a blog URL was provided, fetch it to see recent posts and avoid duplicates
5. Find the intersection between what's happening in the world and what this brand can uniquely say about it
6. Propose 3-5 topics that are timely, specific, and have a clear narrative angle

## Rules
- Every topic MUST connect to something current — a recent event, trend, or shift. No evergreen filler.
- Each topic needs a sharp angle, not just a subject area
- The angle should be something this brand can credibly argue or demonstrate
- Do NOT propose topics the blog has already covered
- Think like a journalist: what's the story? What's the hook?

## Client profile
`)
	sb.WriteString(profile)

	if blogContent != "" {
		sb.WriteString("\n\n## Recent blog posts (avoid duplicating these)\n")
		sb.WriteString(blogContent)
	}

	if homepageContent != "" {
		sb.WriteString("\n\n## Homepage content (for brand context)\n")
		sb.WriteString(homepageContent)
	}

	if approvedTopics != "" {
		sb.WriteString("\n\n## Already approved topics (do NOT re-propose these)\n")
		sb.WriteString(approvedTopics)
	}

	if rejectedTopics != "" {
		sb.WriteString("\n\n## Previously rejected topics (explore different angles)\n")
		sb.WriteString(rejectedTopics)
	}

	if instructions != "" {
		sb.WriteString("\n\n## Extra instructions from the user\nThe user has provided specific guidance for this run. Factor this into your topic selection:\n")
		sb.WriteString(instructions)
	}

	return sb.String()
}

func (b *Builder) ForTopicReview(profile, topicsJSON string) string {
	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString(`

You are a common-sense editorial reviewer. You receive proposed blog topics and evaluate whether each one can be logically angled into a coherent story for this brand's blog.

## Evaluation criteria
For each topic, ask yourself:
1. **Brand fit:** Is the connection between this topic and the brand natural, or does it feel forced?
2. **Angle clarity:** Is the proposed angle specific enough to write a focused, interesting article?
3. **Scope:** Is the topic too broad (unfocused) or too narrow (not enough to say)?
4. **Reader logic:** Would a reader of this brand's blog understand why the brand is writing about this? Would they find it valuable?
5. **Story potential:** Can this be turned into a compelling narrative, not just an informational dump?

## Rules
- Approve topics where the brand connection is natural and the angle is clear
- Reject topics where the angle is forced, too vague, or doesn't serve the audience
- Be specific in your reasoning — say exactly what works or what doesn't
- You are a filter, not a perfectionist. If a topic is good enough with minor adjustments, approve it.

## Client profile
`)
	sb.WriteString(profile)
	sb.WriteString("\n\n## Proposed topics to review\n")
	sb.WriteString(topicsJSON)

	return sb.String()
}
