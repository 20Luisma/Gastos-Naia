#!/bin/bash
lftp -c "
open ftp://if0_41212940:i05Nld9AqCvS5t@ftpupload.net
cd htdocs

# Borrar archivos antiguos del nivel root que ya no se usan
rm index.php
rm -rf lib

# Subir nuevos directorios (mirror sube recursivamente carpetas estructuradas)
mirror -R src src
mirror -R public public
mirror -R templates templates
put composer.json -o composer.json

exit
"
