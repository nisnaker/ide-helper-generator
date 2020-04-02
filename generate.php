<?php

class IdeHelperGenerator
{
    public function run()
    {
        $argv = $_SERVER['argv'];
        array_shift($argv);
        if (0 == count($argv)) {
            echo "usage: php generate.php ext_name_1 ext_name_2 ext_name_3\n";
            echo "like: php generate.php swoole yaf\n";
            return;
        }

        foreach ($argv as $ext_name) {
            try {
                $this->parseExtension($ext_name);
            } catch (ReflectionException $e) {
                echo "ext '{$ext_name}' not found. please check.\n";
            }
        }
    }

    /**
     * @param $ext_name
     *
     * @throws ReflectionException
     */
    private function parseExtension($ext_name)
    {
        $output = "<?php\n\n";

        $this->log('============================');
        $this->log('start parsing ext: ' . $ext_name);
        $ref = new ReflectionExtension($ext_name);

        $output .= $this->getExtInfo($ref) . "\n\n";
        $output .= $this->getExtConstants($ref) . "\n\n";
        $output .= $this->getExtFunctions($ref) . "\n\n";
        $output .= $this->getExtClasses($ref) . "\n\n";

        $filepath = 'doc/' . $ext_name . '.ide.php';
        if(file_put_contents($filepath, $output)){
            $this->log('writed file ' . $filepath);
        }
    }

    /**
     * get phpinfo block
     * @param ReflectionExtension $ref
     * @return string
     */
    private function getExtInfo(ReflectionExtension $ref)
    {
        $output = '';
        $output .= "/**\n * this file is generated by https://github.com/nisnaker/ide-helper-generator\n *\n";
        $output .= " *\n * ext info:\n *";
        ob_start();
        $ref->info();
        $output .= str_replace("\n", "\n * ", ob_get_contents());
        ob_end_clean();
        $output .= "\n */\n";
        return $output;
    }

    /**
     * get ext constants
     * @param ReflectionExtension $ref
     * @return string
     */
    private function getExtConstants(ReflectionExtension $ref)
    {
        $output = '';
        $counter = 0;
        $output .= "/**\n * ext constants:\n */\n\n";
        foreach ($ref->getConstants() as $constant_name => $constant_value) {
            $constant_value = var_export($constant_value, true);
            $output .= sprintf("defined('%s') or define('%s', %s);\n", $constant_name, $constant_name,
                $constant_value);

            $counter++;
        }
        $this->log("found {$counter} constants");

        return $output;
    }

    /**
     * get ext functions
     * @param ReflectionExtension $ref
     * @return string
     */
    private function getExtFunctions(ReflectionExtension $ref)
    {
        $output = '';
        $output .= "/**\n * ext functions:\n */\n\n";

        $counter = 0;
        foreach ($ref->getFunctions() as $refFunc) {
            list($comment, $param_str) = $this->getFuncInfo($refFunc);
            $output .= sprintf("%s\nfunction %s (%s) {}\n\n", $comment, $refFunc->getName(), $param_str);
            $counter++;
        }
        $this->log("found {$counter} functions");

        return $output;
    }

    private function getFuncInfo(ReflectionFunctionAbstract $func, $indent = '')
    {
        $params = [];
        $param_str = [$indent . '/**'];
        foreach ($func->getParameters() as $refParam) {
            $param = '';
            if ($refParam->isPassedByReference()) {
                $param .= '&';
            }
            $param .= '$'.$refParam->getName();
            if($refParam->isOptional()) {
                $param .= ' = NULL';
            }
            $params[] = $param;

            $param_str[] = $indent . sprintf(' * @param %s $%s', $refParam->getType(), $refParam->getName());
        }

        $param_str[] = $indent . ' * @return mixed';
        $param_str[] = $indent . ' */';

        return [implode("\n", $param_str), implode(', ', $params)];
    }

    /**
     * get ext classes
     * @param $ref
     * @return string
     */
    private function getExtClasses(ReflectionExtension $ref)
    {
        $output = '';
        $output .= "/**\n * ext classes:\n */\n\n";
        $tab = str_repeat(' ', 4);
        $longHolder = str_repeat('_', 100);

        $classNames = $ref->getClassNames();

        // array_walk($classNames, function (&$item) use ($longHolder) {
        //     $item = str_replace('_', $longHolder, $item);
        // });

        // usort($classNames, function ($a, $b) {
        //     return strlen($a) > strlen($b);
        // });

        // array_walk($classNames, function (&$item) use ($longHolder) {
        //     $item = str_replace($longHolder, '_', $item);
        // });

        $counter = 0;
        foreach ($classNames as $className) {
            $refClass = new ReflectionClass($className);

            // for swoole
            if ($refClass->getName() != $className) {
                // $output .= "namespace { class {$className} extends \\{$refClass->getName()} {}}\n";
                continue;
            }

            $output .= "namespace {$refClass->getNamespaceName()} {\n";
            $output .= $tab;
            if ($refClass->isInterface()) {
                $output .= 'interface ';
            } else {
                if ($refClass->isAbstract()) {
                    $output .= "abstract ";
                } elseif ($refClass->isFinal()) {
                    $output .= "final ";
                }
                $output .= 'class ';
            }

            $output .= $refClass->getShortName();
            $parentClass = $refClass->getParentClass();
            if ($parentClass) {
                $output .= ' extends \\' . $parentClass->getName();
            }
            $output .= " {\n";

            // constants
            foreach ($refClass->getConstants() as $name => $value) {
                $value = var_export($value, true);
                $output .= "{$tab}{$tab}const {$name} = {$value};\n";
            }

            $output .= "\n";

            // properties
            foreach ($refClass->getProperties() as $refProp) {
                $output .= $tab . $tab;
                if ($refProp->isPublic()) {
                    $output .= 'public ';
                } elseif ($refProp->isProtected()) {
                    $output .= 'protected ';
                } elseif ($refProp->isPrivate()) {
                    $output .= 'private ';
                }

                if ($refProp->isStatic()) {
                    $output .= 'static ';
                }

                $output .= '$' . $refProp->getName() . ';';

                $output .= "\n";
            }

            $output .= "\n";

            // methods
            foreach ($refClass->getMethods() as $refMethod) {
                if($refMethod->isFinal()) {
                    continue;
                }
                $props = $tab . $tab;

                if ($refMethod->isPublic()) {
                    $props .= 'public ';
                } elseif ($refMethod->isProtected()) {
                    $props .= 'protected ';
                } elseif ($refMethod->isPrivate()) {
                    $props .= 'private ';
                }

                if ($refMethod->isStatic()) {
                    $props .= 'static ';
                }

                list($comment, $param_str) = $this->getFuncInfo($refMethod, $tab . $tab );
                $body = '';
                if($refMethod->getName() === '__toString') {
                    $body = 'return "";';
                }elseif ($refMethod->getName() === '__construct' && $refClass->getParentClass()) {
                    $body = 'parent::__construct();';
                }
                $output .= sprintf("%s\n%sfunction %s (%s) {%s}\n\n", $comment, $props, $refMethod->getName(), $param_str, $body);
            }

            $output .= "{$tab}}\n";

            $output .= "}\n\n";

            $counter++;
        }

        $this->log("found {$counter} classes");

        $output = str_replace("\n\n\n", "\n\n", $output);

        return $output;
    }

    private function log($msg)
    {
        fwrite(STDOUT, $msg . "\n");
    }
}

$generator = new \IdeHelperGenerator();
$generator->run();
