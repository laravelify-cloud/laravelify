Bun.serve({ port: Number(process.env.PORT ?? 3000), hostname: "127.0.0.1", fetch: () => new Response("Bun works") });
