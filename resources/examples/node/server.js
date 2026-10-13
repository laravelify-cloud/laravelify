require("http").createServer((q,s)=>s.end("Node.js works")).listen(process.env.PORT||3000)
