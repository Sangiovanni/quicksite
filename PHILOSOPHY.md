# QuickSite — Philosophy

## Don't hide how things work

A core principle of QuickSite is **transparency over abstraction**. The project makes HTML, CSS, and JavaScript easier to work with, but it never hides them. When you edit a node's structure, you're working with real HTML tags and attributes. When you style something, you're writing real CSS properties. The goal is to lower the barrier without removing the floor.

This is intentional. QuickSite is designed so that someone who has never written code can start building pages through the admin panel and, over time, **naturally learn how web development actually works** — not a simplified version of it, but the real thing. Instead of abstracting everything away behind drag-and-drop widgets and proprietary concepts, it exposes the underlying technology in a guided, approachable way.

This extends to the development environment itself. The virtual host requirement, the `public/` and `secure/` separation, the `.htaccess` routing — these mirror how production servers actually work. There's no "dev mode" that behaves differently from deployment. What you see locally is what you get on the server. We've all experienced the "it works on my machine" problem — QuickSite avoids it by keeping your development environment as close to production as possible, from the very first step.

The project started as a personal tool to work faster. The decision to maintain it and keep it open source came from these deeper motivations: making real web development accessible without dumbing it down, and putting users in authentic conditions from day one.

## AI as a tool, not a crutch

QuickSite embraces AI as part of today's development landscape. The built-in AI integration (BYOK) lets users proxy requests to LLMs directly from the admin panel — lowering the barrier to using AI as a real working tool, not just a novelty.

But the approach is deliberate. The API is designed around **structured JSON communication** — every command has typed parameters, validation rules, and predictable responses. This makes it naturally compatible with any AI chatbot or LLM: you can describe what you want in plain language, and the AI can translate that into exact API calls. It works the other way too — AI output maps cleanly onto QuickSite's structured commands.

This serves two purposes. First, it **reduces the cost of AI usage** by letting the system handle structural operations deterministically when it can (adding nodes, editing styles, managing routes) instead of relying on AI for everything. AI is powerful, but it's most effective when guided by well-defined structures rather than given free rein. Second, it **teaches users a modern way of working** — how to communicate effectively with AI tools, how to combine human intent with machine execution, and where the boundaries are.

## SEO and AEO awareness

QuickSite produces real HTML on real URLs, served by a real web server. That sounds obvious, but it matters: a lot of modern site builders ship single-page applications that depend on JavaScript to render anything meaningful. QuickSite's output is the opposite — server-rendered markup, clean per-page URLs, no client-side hydration required to see the content. That's the foundation classical SEO is built on, and it comes for free from the file-based, server-rendered model.

The project also keeps an eye on **AEO** — Answer Engine Optimization — the discipline of making content readable by LLM-driven discovery surfaces (chatbots, AI search, retrieval pipelines). Those crawlers reward the same things SEO has always rewarded: stable URLs, semantic HTML, predictable structure, content that exists in the initial response rather than appearing after a JavaScript bundle runs. The data-driven routes work planned for beta.8 (server-rendered dynamic pages from internal data) is the next concrete step in that direction.

It's not a feature checkbox — it's a directional bias. Decisions about rendering strategy, route structure, and what counts as a "page" are made with both audiences in mind: humans, search engines, and increasingly the language models routing traffic between them.

## How this project was built

Built using a **hybrid human-AI development approach** with GitHub Copilot (Claude).

This project started as a bare draft — hand-written code, no agent involvement — and I never expected it to go this far. I had tried agentic AI workflows earlier and the experience was rough: sloppy edits, missed context, the agent fighting the codebase rather than reading it. I shelved that approach for a while.

When I came back to agentic mode around **November 2025**, something had shifted. The same kind of work that used to feel like a tug-of-war became fast and precise — the agent picked up on conventions already in the codebase, asked sensible questions, and produced changes that fit. Some of that is the tooling getting better; some of it is me getting better at framing what I want and where the boundaries are. Probably both.

By **May 2026** the relationship has flipped. I struggle to imagine going back to working on a project this size without an agent in the loop — not because I can't, but because the speed and contextual accuracy have become part of how I think about the work. That dependency is something I stay aware of, which is part of why the project itself is so deliberate about keeping AI optional and the underlying mechanics visible.

As the project grew beyond what I initially imagined, I let some **vibecoding** happen, mainly in the admin panel and the visual editor. The result is a genuinely hybrid workflow:

- **I handle architecture, structure, and communication layers** — everything that defines how parts of the system talk to each other (routes, API responses, specs, configs). I keep close control over anything that faces the user or the AI (interaction schemas, for example).
- **The agent gets more freedom on the JavaScript side** — JS is the language I'm least comfortable with, and I used this project as an opportunity to learn it more deeply and to test how far an AI agent can build things on its own.
- **I didn't write a lot of code directly** — my role has been mostly architecture, communication design, and review. I refactor things along the way, but the approach is adaptive rather than prescriptive.

It's an honest experiment in human-AI collaboration. I plan to share what I've learned about working with AI agents — how to communicate effectively, where to give freedom, and where to keep control — alongside the project tutorials.

---

[Back to README](README.md)
