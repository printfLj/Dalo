# Daló QR code library

This directory contains the single-file `splitbrain/php-qrcode` SVG encoder,
pinned to upstream commit `d622f56003571caca078ec4fc57d8260179b990f`.
It is included directly by PHP and does not require Composer, GD, or network
access at runtime. The library is MIT-licensed; see `LICENSE` and retain this
attribution when distributing the application.

Daló passes only its random URL-safe registration token to the encoder. It does
not pass names, student IDs, event IDs, or other personal data.

Upstream: https://github.com/splitbrain/php-qrcode
