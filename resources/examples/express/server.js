const app=require("express")();app.get("/",(q,s)=>s.send("Express works"));app.listen(process.env.PORT||3000)
