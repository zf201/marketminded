package content

// Framework defines a storytelling framework with its beats and prompt instruction.
type Framework struct {
	Key              string
	Name             string
	Attribution      string
	ShortDescription string
	BestFor          string
	Beats            string
	Example          string
	PromptInstruction string
}

var Frameworks = []Framework{
	{
		Key:              "pixar",
		Name:             "Pixar Framework",
		Attribution:      "Pixar Studios",
		ShortDescription: "Makes change memorable through emotionally resonant stories. Perfect for presenting new ideas or initiatives that need instant buy-in.",
		BestFor:          "Change management",
		Beats:            "Once upon a time… (Set the scene) / Every day… (The routine) / One day… (A change or conflict) / Because of that… (Immediate consequence) / Because of that… (What happened next) / Until finally… (The resolution)",
		Example:          "Once upon a time, businesses had to buy and manage their own expensive servers. Every day, IT teams would spend hours maintaining them. One day, AWS launched the cloud. Because of that, companies could rent server space on demand. Because of that, startups could scale globally overnight without massive capital. Until finally, the cloud became the standard for businesses everywhere, unlocking a new era of innovation.",
		PromptInstruction: "Tell this story using the Pixar framework.\nBeats: Once upon a time… / Every day… / One day… / Because of that… (x2) / Until finally…\nRules: One section per beat, vivid but concrete, no clichés. End with a crisp CTA.",
	},
	{
		Key:              "golden_circle",
		Name:             "Golden Circle",
		Attribution:      "Simon Sinek",
		ShortDescription: "Inspires action by starting with purpose, not product. Ideal for rallying teams, pitching investors, or building a brand people believe in.",
		BestFor:          "Vision/mission",
		Beats:            "WHY (core belief, purpose) → HOW (unique process, value proposition) → WHAT (products or services)",
		Example:          "Why: We believe in challenging the status quo. How: By making our products beautifully designed and simple to use. What: We just happen to make great computers.",
		PromptInstruction: "Write this as a Golden Circle narrative.\nBeats: WHY (belief) → HOW (method) → WHAT (offering) → CTA.\nRules: Lead with purpose; keep HOW differentiated; make WHAT unmistakable.",
	},
	{
		Key:              "storybrand",
		Name:             "StoryBrand",
		Attribution:      "Donald Miller",
		ShortDescription: "Flips traditional marketing: the customer is the hero, your brand is the guide. Creates marketing that connects by focusing on the customer's journey.",
		BestFor:          "Sales/marketing",
		Beats:            "Character (customer) has a Problem → meets a Guide (brand) with Empathy + Authority → gets a Plan → Call to Action → avoids Failure → achieves Success",
		Example:          "A small business owner (Hero) is struggling to keep track of their finances (Problem). They discover your accounting software (Guide), which offers a simple three-step setup (Plan). They sign up for a free trial (Call to Action) and finally gain control of their cash flow (Success), avoiding the chaos of tax season (Failure).",
		PromptInstruction: "Write this using StoryBrand.\nBeats: Character (customer) + Problem → Guide (us) with Empathy + Authority → Plan (process + success path) → Call to Action (direct + transitional) → Stakes (avoid failure) → Success (after state).\nRules: Customer is hero; we are guide. Short, scannable sentences. Concrete plan (3 steps).",
	},
	{
		Key:              "heros_journey",
		Name:             "Hero's Journey",
		Attribution:      "Joseph Campbell",
		ShortDescription: "The blueprint for epic tales — powerful for founder stories and personal brands because it makes the journey relatable and motivational.",
		BestFor:          "Personal branding",
		Beats:            "Call to Adventure → Crossing the Threshold → Tests, Allies, Enemies → The Ordeal → The Reward → The Road Back & Resurrection",
		Example:          "When a founder shares their story this way, we don't just hear about a company; we see ourselves in their struggle and root for their success.",
		PromptInstruction: "Craft this using the Hero's Journey.\nBeats: Call → Threshold → Trials → Ordeal → Reward → Road Back → Transformation → Return with Elixir → CTA.\nRules: Show vulnerability, stakes, and change. Develop each beat fully.",
	},
	{
		Key:              "three_act",
		Name:             "Three-Act Structure",
		Attribution:      "Classic",
		ShortDescription: "The fundamental architecture of all storytelling. Our brains are wired to understand information this way — perfect for keynotes, strategic plans, or presentations.",
		BestFor:          "Formal presentations",
		Beats:            "Act I: Setup (characters, world, status quo) → Act II: Conflict (problem, rising tension, stakes) → Act III: Resolution (confrontation, new reality, transformation)",
		Example:          "Think of it as: Beginning, Middle, End. It provides a clear, logical flow that keeps your audience engaged.",
		PromptInstruction: "Write this in Three Acts.\nAct I (Setup): context + inciting incident.\nAct II (Conflict): obstacles, rising stakes, decisive choice.\nAct III (Resolution): result, insight, next step.\nRules: Develop each act fully; end with CTA.",
	},
	{
		Key:              "abt",
		Name:             "ABT (And/But/Therefore)",
		Attribution:      "Randy Olson",
		ShortDescription: "The secret weapon for persuasive emails, project updates, or elevator pitches. Distills complex ideas into a clear, compelling narrative in three steps.",
		BestFor:          "Daily communication",
		Beats:            "AND (establish context, agreement) → BUT (introduce the conflict or problem) → THEREFORE (propose the solution or resolution)",
		Example:          "We need to increase our market share, AND our competitors are gaining on us. BUT our current marketing strategy isn't delivering the results we need. THEREFORE, we must pivot to a new digital-first campaign focused on our core demographic.",
		PromptInstruction: "Write this using ABT (And/But/Therefore).\nAND: the situation + shared context.\nBUT: the tension or change making the status quo untenable.\nTHEREFORE: the action to take and expected result.\nRules: Assertive; end with CTA.",
	},
}

// FrameworkByKey returns the framework with the given key, or nil if not found.
func FrameworkByKey(key string) *Framework {
	for i := range Frameworks {
		if Frameworks[i].Key == key {
			return &Frameworks[i]
		}
	}
	return nil
}
