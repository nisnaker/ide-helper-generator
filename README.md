# ide-helper-generator
Generate ide-helper file for php-extension, like yaf, swoole, etc.

command:
```
$ php generate.php swoole
============================
start parsing ext: swoole
found 73 constants
found 28 functions
found 31 classes
writed file doc/swoole.ide.php
```

for simple use you can just save [https://raw.githubusercontent.com/nisnaker/ide-helper-generator/master/doc/swoole.ide.php](https://raw.githubusercontent.com/nisnaker/ide-helper-generator/master/doc/swoole.ide.php) to your ide's include path. you can create a issue telling me to add other extension-include-files.
