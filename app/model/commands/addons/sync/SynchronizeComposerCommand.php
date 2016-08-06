<?php

namespace App\Model\Commands\Addons\Sync;

use App\Model\Commands\BaseCommand;
use App\Model\ORM\Addon\Addon;
use App\Model\ORM\Addon\AddonRepository;
use App\Model\ORM\Composer\Composer;
use App\Model\WebServices\Composer\Service;
use Exception;
use Nette\InvalidStateException;
use Nette\Utils\Arrays;
use Nextras\Orm\Collection\ICollection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tracy\Debugger;

final class SynchronizeComposerCommand extends BaseCommand
{

    /** @var AddonRepository */
    private $addonRepository;

    /** @var Service */
    private $composer;

    /**
     * @param AddonRepository $addonRepository
     * @param Service $composer
     */
    public function __construct(AddonRepository $addonRepository, Service $composer)
    {
        parent::__construct();
        $this->addonRepository = $addonRepository;
        $this->composer = $composer;
    }

    /**
     * Configure command
     */
    protected function configure()
    {
        $this
            ->setName('app:addons:sync:composer')
            ->setDescription('Synchronize composer detailed information');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ICollection|Addon[] $addons */
        $addons = $this->addonRepository->findComposers();

        // DO YOUR JOB ===============================================

        $counter = 0;
        foreach ($addons as $addon) {
            try {
                // Skip addon with bad data
                if (($extra = $addon->github->extra)) {
                    if (($composer = $extra->get('composer', FALSE))) {

                        if (!isset($composer['name'])) {
                            throw new InvalidStateException('No composer name at ' . $addon->fullname);
                        }

                        list ($owner, $repo) = explode('/', $composer['name']);

                        // Create composer entity if not exist
                        if (!$addon->composer) {
                            $addon->composer = new Composer();
                        }

                        // Basic info
                        $addon->composer->name = !empty($name = Arrays::get($composer, 'name', NULL)) ? $name : NULL;
                        $addon->composer->description = !empty($description = Arrays::get($composer, 'description', NULL)) ? $description : NULL;
                        $addon->composer->type = !empty($type = Arrays::get($composer, 'type', NULL)) ? $type : NULL;

                        // Downloads
                        if (($stats = $this->composer->repo($owner, $repo))) {
                            $addon->composer->downloads = Arrays::get($stats, ['package', 'downloads', 'total'], 0);
                        }

                        // Keywords
                        $keywords = Arrays::get($composer, 'keywords', []);
                        $addon->composer->keywords = $keywords ? implode(',', $keywords) : NULL;

                        // Persist
                        $this->addonRepository->persistAndFlush($addon);

                        // Increase counting
                        $counter++;
                    } else {
                        $output->writeln('Skip (composer) [no composer data]: ' . $addon->fullname);
                    }
                } else {
                    $output->writeln('Skip (composer) [no extra data]: ' . $addon->fullname);
                }
            } catch (Exception $e) {
                Debugger::log($e, Debugger::EXCEPTION);
                $output->writeln('Skip (composer) [exception]: ' . $e->getMessage());
            }
        }

        $output->writeln(sprintf('Updated %s composer addons', $counter));
    }
}