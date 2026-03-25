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
		PromptInstruction: "Structure this content using the Pixar storytelling framework. Follow these beats:\n1. Once upon a time… (Set the scene and the status quo)\n2. Every day… (Describe the routine, the normal)\n3. One day… (Introduce a change or conflict)\n4. Because of that… (Explain the immediate consequence)\n5. Because of that… (Show what happened next)\n6. Until finally… (Reveal the resolution)",
	},
	{
		Key:              "golden_circle",
		Name:             "Golden Circle",
		Attribution:      "Simon Sinek",
		ShortDescription: "Inspires action by starting with purpose, not product. Ideal for rallying teams, pitching investors, or building a brand people believe in.",
		BestFor:          "Vision/mission",
		Beats:            "WHY (core belief, purpose) → HOW (unique process, value proposition) → WHAT (products or services)",
		Example:          "Why: We believe in challenging the status quo. How: By making our products beautifully designed and simple to use. What: We just happen to make great computers.",
		PromptInstruction: "Structure this content using Simon Sinek's Golden Circle framework. Follow these beats:\n1. WHY — Start with the core belief or purpose\n2. HOW — Explain the unique process or value proposition\n3. WHAT — Describe the products or services\nLead with purpose. Sell the why before the what.",
	},
	{
		Key:              "storybrand",
		Name:             "StoryBrand",
		Attribution:      "Donald Miller",
		ShortDescription: "Flips traditional marketing: the customer is the hero, your brand is the guide. Creates marketing that connects by focusing on the customer's journey.",
		BestFor:          "Sales/marketing",
		Beats:            "Character (customer) has a Problem → meets a Guide (brand) with Empathy + Authority → gets a Plan → Call to Action → avoids Failure → achieves Success",
		Example:          "A small business owner (Hero) is struggling to keep track of their finances (Problem). They discover your accounting software (Guide), which offers a simple three-step setup (Plan). They sign up for a free trial (Call to Action) and finally gain control of their cash flow (Success), avoiding the chaos of tax season (Failure).",
		PromptInstruction: "Structure this content using Donald Miller's StoryBrand framework. Follow these beats:\n1. Character — The customer/reader as the hero\n2. Problem — The challenge they face\n3. Guide — Position the brand as the wise guide with empathy and authority\n4. Plan — Give them a clear plan (process + success path)\n5. Call to Action — Direct and transitional CTAs\n6. Stakes — What failure looks like if they don't act\n7. Success — The transformation after they act",
	},
	{
		Key:              "heros_journey",
		Name:             "Hero's Journey",
		Attribution:      "Joseph Campbell",
		ShortDescription: "The blueprint for epic tales — powerful for founder stories and personal brands because it makes the journey relatable and motivational.",
		BestFor:          "Personal branding",
		Beats:            "Call to Adventure → Crossing the Threshold → Tests, Allies, Enemies → The Ordeal → The Reward → The Road Back & Resurrection",
		Example:          "When a founder shares their story this way, we don't just hear about a company; we see ourselves in their struggle and root for their success.",
		PromptInstruction: "Structure this content using the Hero's Journey framework. Follow these beats:\n1. Call to Adventure — The initial idea or problem\n2. Crossing the Threshold — Committing to the journey\n3. Tests, Allies, Enemies — Challenges, mentors, competitors\n4. The Ordeal — The biggest challenge, a near-failure moment\n5. The Reward — The breakthrough or success\n6. The Road Back & Resurrection — Returning with new knowledge to transform the world",
	},
	{
		Key:              "three_act",
		Name:             "Three-Act Structure",
		Attribution:      "Classic",
		ShortDescription: "The fundamental architecture of all storytelling. Our brains are wired to understand information this way — perfect for keynotes, strategic plans, or presentations.",
		BestFor:          "Formal presentations",
		Beats:            "Act I: Setup (characters, world, status quo) → Act II: Conflict (problem, rising tension, stakes) → Act III: Resolution (confrontation, new reality, transformation)",
		Example:          "Think of it as: Beginning, Middle, End. It provides a clear, logical flow that keeps your audience engaged.",
		PromptInstruction: "Structure this content using the Three-Act Structure. Follow these beats:\n1. Act I — Setup: Introduce the context and the status quo. What is the current situation?\n2. Act II — Conflict: Introduce the problem or rising tension. This is where the struggle happens and stakes are raised.\n3. Act III — Resolution: The conflict is confronted and a new reality is established. What is the transformation or payoff?",
	},
	{
		Key:              "abt",
		Name:             "ABT (And/But/Therefore)",
		Attribution:      "Randy Olson",
		ShortDescription: "The secret weapon for persuasive emails, project updates, or elevator pitches. Distills complex ideas into a clear, compelling narrative in three steps.",
		BestFor:          "Daily communication",
		Beats:            "AND (establish context, agreement) → BUT (introduce the conflict or problem) → THEREFORE (propose the solution or resolution)",
		Example:          "We need to increase our market share, AND our competitors are gaining on us. BUT our current marketing strategy isn't delivering the results we need. THEREFORE, we must pivot to a new digital-first campaign focused on our core demographic.",
		PromptInstruction: "Structure this content using the ABT (And/But/Therefore) framework. Follow these beats:\n1. AND — Establish the context and shared agreement\n2. BUT — Introduce the conflict or the problem that makes the status quo untenable\n3. THEREFORE — Propose the solution or resolution\nKeep it tight and assertive. This framework is about clarity and momentum.",
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
