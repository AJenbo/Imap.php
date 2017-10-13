Imap.php
================

[![Codacy Badge](https://api.codacy.com/project/badge/Grade/b8ab08f9d74345eca587148c0d3b365c)](https://www.codacy.com/app/AJenbo/imap.php?utm_source=github.com&amp;utm_medium=referral&amp;utm_content=AJenbo/imap.php&amp;utm_campaign=Badge_Grade)

This is a rewrite of the class found at http://www.phpclasses.org/package/2351-PHP-Access-IMAP-mailboxes-without-PHP-IMAP-extension.html as I found it lacking in some asspects and over reaching in otheres.

The class still acts in a command respond fashion instead of per line evaluation, witch is a violation of the IMAP specefication.
Currently it is lacking processessing for fetch responces
