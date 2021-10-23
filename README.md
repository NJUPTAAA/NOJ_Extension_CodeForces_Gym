# NOJ_Extension_Codeforces_Gym
Codeforces Gym interface for NOJ

# Configuration

Judge Agent should be a valid CodeForces account.

We highly recommend **5~10 Judge Agents** enabled for CodeForces Gym NOJ Babel Extension.

Set username the handle and password the password from CodeForces.

**Setting email as handle will break some features like `babel:judge`.**

# Usage

Require CodeForces:

```bash
php artisan babel:require codeforces
```

Update CodeForces:

```bash
php artisan babel:update codeforces
```

Install CodeForces:

```bash
php artisan babel:install codeforces
```

Crawl CodeForces:

```bash
php artisan babel:crawl codeforces
```

# FAQs

**Q:** Can I use the same agent from my codeforces extension?

**A:** You **CAN**, but you **shouldn't**. Because CodeForces has rate limit for accounts, having 2 separated extension sharing the same rate limit pool is not best practise for maintenance. For having different sets of judge agents for CodeForces and CodeForces Gym, you can controll them separately. But if you want to set them the same, it is totally fine and workable.
