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

## How this project was built

Built using a **hybrid human-AI development approach** with GitHub Copilot (Claude).

This project started as a bare draft — I never expected it to go this far. As it grew beyond what I initially imagined, I let some **vibecoding** happen, mainly in the admin panel and the visual editor. The result is a genuinely hybrid workflow:

- **I handle architecture, structure, and communication layers** — everything that defines how parts of the system talk to each other (routes, API responses, specs, configs). I keep close control over anything that faces the user or the AI (interaction schemas, for example).
- **The agent gets more freedom on the JavaScript side** — JS is the language I'm least comfortable with, and I used this project as an opportunity to learn it more deeply and to test how far an AI agent can build things on its own.
- **I didn't write a lot of code directly** — my role has been mostly architecture, communication design, and review. I refactor things along the way, but the approach is adaptive rather than prescriptive.

It's an honest experiment in human-AI collaboration. I plan to share what I've learned about working with AI agents — how to communicate effectively, where to give freedom, and where to keep control — alongside the project tutorials.

---

[Back to README](README.md)
