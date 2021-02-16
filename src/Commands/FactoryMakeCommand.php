<?php

namespace Nwidart\Modules\Commands;

use Illuminate\Support\Str;
use Nwidart\Modules\Support\Config\GenerateConfigReader;
use Nwidart\Modules\Support\Stub;
use Nwidart\Modules\Traits\ModuleCommandTrait;
use Symfony\Component\Console\Input\InputArgument;

class FactoryMakeCommand extends GeneratorCommand
{
    use ModuleCommandTrait;

    /**
     * The name of argument name.
     *
     * @var string
     */
    protected $argumentName = 'name';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'module:make-factory';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new model factory for the specified module.';

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the model.'],
            ['module', InputArgument::OPTIONAL, 'The name of module will be used.'],
        ];
    }

    /**
     * @return mixed
     */
    protected function getTemplateContents()
    {
        $module = $this->laravel['modules']->findOrFail($this->getModuleName());

        return (new Stub('/factory.stub', [
            'NAMESPACE' => $this->getClassNamespace($module),
            'NAME' => $this->getModelName(),
            'MODEL_NAMESPACE' => $this->getModelNamespace(),
        ]))->render();
    }

    /**
     * @return mixed
     */
    protected function getDestinationFilePath()
    {
        $path = $this->laravel['modules']->getModulePath($this->getModuleName());

        $factoryPath = GenerateConfigReader::read('factory');

        return $path . $factoryPath->getPath() . '/' . $this->getFileName();
    }

    /**
     * @return string
     */
    private function getFileName()
    {
        return Str::studly($this->argument('name')) . 'Factory.php';
    }

    /**
     * @return mixed|string
     */
    private function getModelName()
    {
        return Str::studly($this->argument('name'));
    }

    /**
     * Get default namespace.
     *
     * @return string
     */
    public function getDefaultNamespace(): string
    {
        $module = $this->laravel['modules'];

        return $module->config('paths.generator.factory.namespace') ?: $module->config('paths.generator.factory.path');
    }

    /**
     * Get model namespace.
     *
     * @return string
     */
    public function getModelNamespace(): string
    {
        return $this->laravel['modules']->config('namespace') . '\\' . $this->laravel['modules']->findOrFail($this->getModuleName()) . '\\' . $this->laravel['modules']->config('paths.generator.model.path', 'Entities');
    }

    /**
     * Override default handle to import factory usage in Model
     *
     * @return int
     */
    public function handle(): int
    {
        $handle = parent::handle();

        $modelFile = base_path(
            sprintf('%s/%s.php',
                str_replace('\\', '/', $this->getModelNamespace()),
                $this->getModelName()
            )
        );

        if (file_exists($modelFile)) {
            $content = file_get_contents($modelFile);
            if (!strstr($content, 'protected static function newFactory()')) {
                $patternInnerClass = '/\;.*class.*\{(?<class>.*?)\}/is';
                $patternUsedClasses = '/namespace.*?\;(?<uses>.*\;).*class.*\{.*\}/s';
                $patternDeclaredUses = '/^(\W+)use (?<uses>.*?)\;/is';

                preg_match($patternInnerClass, $content, $matchesInnerClass);
                preg_match($patternUsedClasses, $content, $matchesUsedClasses);

                $contentInnerClass = $matchesInnerClass['class'];
                $contentUsedClasses = $matchesUsedClasses['uses'];

                preg_match($patternDeclaredUses, $contentInnerClass, $matchesDeclaredUses);

                $contentDeclaredUses = $matchesDeclaredUses['uses'];

                if (!strstr($contentUsedClasses, 'use Illuminate\Database\Eloquent\Factories\HasFactory;')) {
                    $contentUsedClasses .= '\nuse Illuminate\Database\Eloquent\Factories\HasFactory;\n';
                }

                $useIsDeclaredInClass = false;
                if (empty($contentDeclaredUses)) {
                    $contentDeclaredUses = '    use HasFactory;';
                } else {
                    if (!strstr($contentDeclaredUses, 'HasFactory')) {
                        $contentDeclaredUses .= ', HasFactory';
                    }
                    $contentDeclaredUses = '    use ' . $contentDeclaredUses . ';';
                    $useIsDeclaredInClass = true;
                }

                $contentStub = (new Stub('/model-factory.stub', [
                    'MODULE_NAMESPACE' => $this->laravel['modules']->config('namespace'),
                    'MODULE' => $this->getModuleName(),
                    'NAME' => $this->getModelName(),
                ]))->render();

                $contentInnerClass .= $contentStub;

                if ($useIsDeclaredInClass) {
                    $contentInnerClass = preg_replace($patternDeclaredUses, $contentDeclaredUses, $contentInnerClass);
                } else {
                    $contentInnerClass = $contentDeclaredUses . '\n' . $contentInnerClass;
                }

                $x = preg_replace('class.*\{(.*?)\}/is', $contentInnerClass, $content);
                dump($x);

            }

        }

        return $handle;
    }
}
