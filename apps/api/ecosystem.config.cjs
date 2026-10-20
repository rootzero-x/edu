// pm2 — faqat shu ilova, boshqa jarayonlarga tegmaydi.
module.exports = {
  apps: [{
    name: "tisu-api",
    cwd: __dirname,
    script: "node_modules/.bin/tsx",
    args: "--env-file=.env src/main.ts",
    instances: 1,
    max_memory_restart: "400M",
    env: { NODE_ENV: "production" },
  }],
};
