# LeanBB - A Simple Single-File PHP + SQLite Forum

![LeanBB Screenshot](https://raw.githubusercontent.com/aquasuroa/LeanBB/refs/heads/master/screenshot.png)

**LeanBB** is a very basic forum built with just one PHP file and SQLite, aiming to be simple and easy to set up. It’s heavily inspired by **DFFZMXJ**’s wonderful "forum-by-a-single-dog" project, and I’m so grateful for her work, which gave me a starting point. With some help from **Gemini 2.5 Pro** and **Grok 3**, I’ve tried to add a few features, but I’m still learning PHP, so it’s far from perfect. 😅 This is a small project for fun or learning, and I hope it’s useful to someone!

---

## 🌟 What It Does

LeanBB tries to offer a straightforward forum experience with a few simple features:

- **SQLite Storage**: Uses SQLite instead of JSON for storing data. Needs PHP’s `pdo_sqlite` extension.
- **Basic Features**:
  - **Discussion Boards**: Group posts into different topics. 🏷️
  - **Admin Panel**: A simple way to manage users, posts, and boards. 👑
  - **Pagination**: Helps browse through lots of posts. 📄
  - **Search**: Find posts with a keyword. 🔍
  - **User Profiles**: See what someone has posted. 👤
  - **Settings**: Change the forum’s name, logo, or other basics. ⚙️
- **Mobile-Friendly**: Works okay on phones and computers with a plain CSS design.
- **Fewer Errors**: Fixed some issues with links on Apache servers to avoid 404 pages.

---

## ⚠️ What It’s Not

I’m just a beginner, and LeanBB is a learning project, so it has plenty of limitations:

- **Messy Code**: It works, but the code isn’t very tidy or easy to follow. There might be bugs, and I’d love help fixing them!
- **Hard to Update**: I had to simplify things to make it work with AI tools, so it’s not great for big changes.
- **Not for Serious Use**: It’s fun for small projects or practice, but **please don’t use it for important or busy websites**.
- **Missing Features**: Things like editing posts or advanced admin tools aren’t there yet.

I’d be so thankful for any advice or suggestions to make it better!

---

## 🚀 Getting Started

Setting up LeanBB is pretty easy, but here’s how to do it carefully:

### What You Need
- PHP 8.0 or higher with `pdo_sqlite` enabled.
- A web server (like Apache or Nginx) that can write to the SQLite database file.

### Steps
1. **Set Up**:
   - Grab `index.php` from the [GitHub repository](https://github.com/aquasuroa/LeanBB).
   - Put `index.php` in your server’s web folder.
   - Open it in your browser, and it should create the SQLite database by itself.

2. **Stay Safe**:
   - Open `index.php` and change these important settings:
     - `ADMIN_PASSWORD_DEFAULT` (default: `'password'`): Pick a strong, unique password.
     - `DB_SALT` (default: `'change_this_secret_salt_please'`): Use a random string to keep the database safer.
   - **Important**: Leaving these unchanged could make your forum less secure!

3. **Log In as Admin**:
   - Go to `/admin` and sign in:
     - Username: `admin`
     - Password: (whatever you set in `ADMIN_PASSWORD_DEFAULT`)
   - Check out "Site Settings" to tweak the forum’s name, logo, or other details.

4. **For Apache**:
   - If you’re using Apache, add the `.htaccess` file from the repository to avoid link errors.

---

## 🔧 Things to Fix

LeanBB is still rough around the edges, and I know it needs work:

- **Admin Tools**: The admin area is very basic and can feel clunky.
- **User Options**: You can’t edit or delete your own posts yet, so everything you post stays.
- **Speed**: It might slow down if too many people use it at once.
- **Security**: It has some basic protections, but I haven’t checked it thoroughly for safety.

I’d really appreciate any help or ideas to improve these!

---

## 💡 Ideas for Using It

LeanBB is best for small, simple things like:

- **Short-Term Chats**: Set up a forum for an event, club, or class.
- **Learning PHP**: Play with a basic PHP and SQLite project.
- **Testing Ideas**: Try out a forum without a big setup.
- **Just for Fun**: Mess around with the code—it’s simple enough to experiment with!

---

## 🙏 Thanks

This project wouldn’t exist without **DFFZMXJ** and her "forum-by-a-single-dog." Her work inspired me, and I’m so thankful for it. If you have questions about her original project, you can reach her at:

📧 `dffzmxj@qq.com`

Please be kind—she doesn’t have to help with my version. 😊

---

## 🐛 How to Help

I’d love any support to make LeanBB better! If you want to help:

- **Find Bugs**: Tell me about problems by opening an issue on [GitHub](https://github.com/aquasuroa/LeanBB).
- **Share Fixes**: If you know PHP, I’d be thrilled to get your code suggestions.
- **Give Ideas**: Even little thoughts can help a newbie like me learn.

Please be kind and respectful when sharing—I’m still figuring things out!

---

## 📜 License

LeanBB uses the [MIT License](LICENSE), just like the original project. You’re welcome to use, change, or share it however you like.

---

## 🌼 A Little Note

LeanBB is a tiny project I worked on to learn and have fun, built with a lot of help from AI and DFFZMXJ’s ideas. It’s not fancy, but I hope it’s a nice starting point for someone out there. Thanks so much for taking a look—I hope you enjoy poking around with it!

*Made with ❤️ by Aquasuroa, inspired by DFFZMXJ.*

---

[![GitHub Issues](https://img.shields.io/github/issues/aquasuroa/LeanBB)](https://github.com/aquasuroa/LeanBB/issues)
[![License](https://img.shields.io/github/license/aquasuroa/LeanBB)](LICENSE)
