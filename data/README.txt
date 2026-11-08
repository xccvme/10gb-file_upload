admin.json          login admin {"username","password"}. Edit -> langsung berlaku. Password boleh plain atau bcrypt ($2y$...).
tokens.json         link download bertoken (dibuat otomatis). Kosongkan jadi {} = cabut semua link.
login_attempts.json anti brute-force (5x salah = kunci 5 menit per IP). Aman dihapus.
