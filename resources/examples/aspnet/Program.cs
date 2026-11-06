var app = WebApplication.CreateBuilder(args).Build();

app.MapGet("/", () => "ASP.NET Core works");

app.Run();
