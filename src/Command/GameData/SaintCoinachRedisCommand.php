<?php

namespace App\Command\GameData;

use App\Command\CommandHelperTrait;
use App\Common\Service\Redis\Redis;
use App\Common\Utils\System;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\Data\FileSystemCache;
use App\Service\Data\DataHelper;
use App\Service\Data\FileSystem;
use App\Service\GamePatch\Patch;

/**
 * This is a bit mad and to be replaced by: https://github.com/xivapi/xivapi-data
 */
class SaintCoinachRedisCommand extends Command
{
    use CommandHelperTrait;
    
    const MAX_DEPTH = 3;
    const SAVE_TO_REDIS = true;
    const REDIS_DURATION = (60 * 60 * 24 * 365 * 10); // 10 years
    const ZERO_CONTENT = [
        'GatheringType',
        'CraftType',
        'Cabinet',
        'World',
        'RecipeNotebookList',
    ];

    /** @var Patch */
    protected $patch;
    /** @var array */
    protected $save;
    /** @var array */
    protected $links = [];
    /** @var array */
    protected $ids = [];
    /** @var int */
    protected $maxDepth = self::MAX_DEPTH;

    protected function configure()
    {
        $this
            ->setName('SaintCoinachRedisCommand')
            ->setDescription('Build content data from the CSV files and detect content links')
            ->addArgument('file_start', InputArgument::REQUIRED, 'The required starting position for the data')
            ->addArgument('file_count', InputArgument::REQUIRED, 'The amount of files to process in 1 go')
            ->addArgument('fast', InputArgument::OPTIONAL, 'Skip all questions and use default values')
            ->addArgument('force_content_name', InputArgument::OPTIONAL, 'Forced content name')
            ->addArgument('force_content_id', InputArgument::OPTIONAL, 'Forced content name');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->patch = new Patch();
        
        $this->setSymfonyStyle($input, $output);
        $start = $this->input->getArgument('file_start');
        $end   = $start + $this->input->getArgument('file_count');
        
        $this->title("CONTENT UPDATE: {$start} --> {$end}");
        $this->startClock();
    
        $this->checkSchema();
        $this->checkVersion();
        #$this->checkCache();
    
        // this forces exceptions to be thrown
        set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) {
            # print_r($errcontext);
            throw new \Exception("{$errfile} {$errline} - {$errstr}", 0);
        });
    
        $this->buildData();
        
        $this->endClock();
    }

    /**
     * Build Da Data!!
     */
    private function buildData()
    {
        $focusName  = $this->input->getArgument('force_content_name');
        $focusId    = $this->input->getArgument('force_content_id');
        
        if ($focusName || $focusId) {
            $this->io->table(
                [ 'Focus Name', 'Focus ID' ],
                [ [$focusName, $focusId] ]
            );
        }
        
        // chunk schema as doing everything in 1 go is costly
        $chunkySchema = $this->schema;
        $chunkySchema = array_splice(
            $chunkySchema,
            $this->input->getArgument('file_start'),
            $this->input->getArgument('file_count')
        );
    
        if (!$chunkySchema) {
            $this->io->text('No data to process!');
            return;
        }
        
        // stats
        $count = 0;
        $total = count($chunkySchema);
        
        // start a pipeline
        foreach ($chunkySchema as $contentName => $contentSchema) {
            $count++;
            
            if ($focusName && $focusName != $contentName) {
                # $this->io->text("Sheet: {$count}/{$total}    <info>SKIPPED {$contentName}</info>");
                continue;
            }
            
            // skip ENpcBase, will do it on its own...
            if (!$focusName && $contentName == 'ENpcBase') {
                continue;
            }
            
            // skip level as it takes about 50 years
            if ($contentName == 'Level') {
                continue;
            }
    
            $this->maxDepth = $contentName == 'ENpcBase' ? 1 : self::MAX_DEPTH;
            
            // load all content for that schema
            $allContentData = FileSystem::load($contentName, 'json');

            // build content (this saves it)
            $section = (new ConsoleOutput())->section();
    
            $memory   = number_format(System::memory());
            $section->writeln("[{$memory}MB memory] Sheet: {$count}/{$total} <info>{$contentName}</info>");
            
            foreach ($allContentData as $contentId => $contentData) {
                if ($focusId && $focusId != $contentId) {
                    continue;
                }
   
                $this->buildContent($contentId, $contentName, $contentSchema, clone $contentData, 0, true);
            }
            
            unset($allContentData);
            
            // save data
            if ($this->save) {
                $idCount = 0;
                $saveCount = 0;
    
                Redis::Cache()->startPipeline();
                foreach ($this->save as $key => $data) {
                    $idCount++;
    
                    if (!$data || empty($data) || !isset($data->ID)) {
                        continue;
                    }

                    // Set content url and some placeholders
                    $data->Url = "/{$contentName}/{$data->ID}";
                    $data->GameContentLinks = null;
                    
                    // save
                    $this->saveContentId($data->ID, $contentName);
                    Redis::Cache()->set($key, $data, self::REDIS_DURATION);
                    
                    if ($idCount % 250 == 0) {
                        $saveCount++;
                        Redis::Cache()->executePipeline();
                        Redis::Cache()->startPipeline();
                    }
                }
                Redis::Cache()->executePipeline();
                
                unset($this->save);
            }
            
            unset($section);
        }
    
        //
        // save the ids
        //
        
        $this->io->text('<fg=cyan>Caching content ID lists</>');
        Redis::Cache()->startPipeline();
        foreach ($this->ids as $contentName => $idList) {
            // this prevents id 0 being added when it has no zero content.
            if (!in_array($contentName, self::ZERO_CONTENT) && $idList[0] == '0') {
                unset($idList[0]);
            }
            
            $idList = (array)$idList;
            Redis::Cache()->set("ids_{$contentName}", $idList, self::REDIS_DURATION);
        }
        Redis::Cache()->executePipeline();
        $this->complete();
        
        //
        // Save links
        //
        
        $this->io->text('<fg=cyan>Building content connection links</>');
        $this->io->progressStart(count($this->links));
        foreach ($this->links as $linkTarget => $contentData) {
            $key = "connections_{$linkTarget}";
            $contentLinks = Redis::Cache()->get($key) ?: [];
            $contentLinks = (Array)$contentLinks;
    
            // process each target info
            foreach($contentData as $contentTarget => $targetInfo) {
                // grab existing and append on content target
                $contentLinks[$contentTarget] = 1;
            }
    
            // save
            Redis::Cache()->set($key, $contentLinks, self::REDIS_DURATION);
            $this->io->progressAdvance();
        }
        $this->io->progressFinish();
    }
    
    /**
     * Build content
     */
    private function buildContent($contentId, $contentName, $contentSchema, $content, $depth = 0, $save = null)
    {
        // if the max depth has been hit, return the content and don't link any data to it.
        if ($depth >= $this->maxDepth) {
            return $content;
        }
        
        // if we have a schema, build the data
        if ($contentSchema) {
            foreach ($contentSchema->definitions as $definition) {
                if (!isset($definition->name) && !isset($definition->type)) {
                    continue;
                }
                
                
                // is this a repeater definition?
                if (!isset($definition->name) && $definition->type === 'repeat') {
                    $this->handleSingleRepeat($contentId, $contentName, $content, $depth, $definition);
                    continue;
                }
                
                // not a repeater, handle it
                $this->handleDefinition($contentId, $contentName, $content, $definition, $depth + 1);
            }
        }
 
        // only save at a depth of 0
        if ($save && $content) {
            $content->ID = $content->ID === null ? 0 : $content->ID;
            $this->save["xiv_{$contentName}_{$contentId}"] = clone $content;
        } else if ($save && !$content) {
            $this->io->error("No Data: {$contentName} @{$contentId}");
        }
        
        return $content;
    }
    
    /**
     * Handle a single repeat definition
     */
    private function handleSingleRepeat($contentId, $contentName, $content, $depth, $definition)
    {
        // is this a grouped definition ?
        if (isset($definition->definition->type) && $definition->definition->type == 'group') {
            $this->handleMultiRepeat($contentId, $contentName, $content, $depth, $definition);
            return;
        }
        
        // loop through all definitions
        foreach (range(0, $definition->count - 1) as $num) {
            $originalDefinition = clone $definition;
            $tempDefinition = clone $definition->definition;
            
            if (!isset($tempDefinition->name) && isset($tempDefinition->definition->type) && $tempDefinition->definition->type == 'group') {
                $this->handleMultiMultiRepeat($contentId, $contentName, $content, $depth, $originalDefinition);
                continue;
            }

            // ignore for now, it is likely because its a repeater in a repeater, that has no members
            if (!isset($tempDefinition->name)) {
                continue;
            }
            
            $tempDefinition->name = "{$tempDefinition->name}{$num}";
            $this->handleDefinition($contentId, $contentName, $content, $tempDefinition, $depth + 1);
        }
        
        unset($tempDefinition);
    }
    
    /**
     * Handle a multi-MULTI repeat definition
     */
    private function handleMultiMultiRepeat($contentId, $contentName, $content, $depth, $definition)
    {
        // loop through end number
        foreach (range(0, $definition->count - 1) as $num1) {
            // loop through mid number
            foreach (range(0, $definition->definition->count - 1) as $num2) {
                // loop through members
                foreach ($definition->definition->definition->members as $memberDefinition) {
                    $tempDefinition = clone $memberDefinition;
                    $tempDefinition->name = "{$tempDefinition->name}{$num2}{$num1}";
                    $this->handleDefinition($contentId, $contentName, $content, $tempDefinition, $depth + 1);
                }
            }
        }
    }
    
    /**
     * Handle a multi repeat definition
     */
    private function handleMultiRepeat($contentId, $contentName, $content, $depth, $definition)
    {
        // loop through all definitions
        foreach (range(0, $definition->count - 1) as $num) {
            // loop through all members in the definition
            foreach ($definition->definition->members as $memberDefinition) {
                $originalDefinition = clone $definition;
                $tempDefinition = clone $memberDefinition;
                
                if (!isset($tempDefinition->name)) {
                    $this->handleMultiGroupRepeatDefinition($contentId, $contentName, $content, $depth + 1, $originalDefinition);
                    continue;
                }
                
                $tempDefinition->name = "{$tempDefinition->name}{$num}";
                $this->handleDefinition($contentId, $contentName, $content, $tempDefinition, $depth + 1);
            }
        }
        
        unset($tempDefinition);
    }
    
    /**
     * Handle multi group repeat definition
     */
    private function handleMultiGroupRepeatDefinition($contentId, $contentName, $content, $depth, $definition)
    {
        // loop through end number
        foreach (range(0, $definition->count - 1) as $num1) {
            // loop through members
            foreach ($definition->definition->members as $memberDefinition) {
                // loop through mid number
                foreach (range(0, $memberDefinition->count - 1) as $num2) {
                    $tempDefinition = clone $memberDefinition->definition;
                    $tempDefinition->name = "{$tempDefinition->name}{$num2}{$num1}";

                    // handle the definition
                    $this->handleDefinition($contentId, $contentName, $content, $tempDefinition, $depth + 1);
                }
            }
        }
    }
    
    /**
     * Handle the definition
     */
    private function handleDefinition($contentId, $contentName, $content, $definition, $depth)
    {
        // simplify column names
        $definition->name = DataHelper::getSimpleColumnName($definition->name);
        $definition->name = DataHelper::getReplacedName($contentName, $definition->name);
        
        // if definition is set, ignore it
        if (isset($content->{$definition->name}) && is_object($content->{$definition->name})) {
            return null;
        }
        
        // special one because SE is crazy and link level_item id by the ACTUAL level...
        if ($contentName == 'Item' && isset($definition->name) && $definition->name == 'LevelItem') {
            return null;
        }
        
        // handle link type definition
        if (isset($definition->converter) && $definition->converter->type == 'link') {
            // id of linked data
            $linkId = $content->{$definition->name} ?? null;
    
            // target name of linked data
            $linkTarget = $definition->converter->target;
    
            // add link target and target id
            $content->{$definition->name} = null;
            $content->{$definition->name ."Target"} = $linkTarget;
            $content->{$definition->name ."TargetID"} = $linkId;
    
            // if link id is an object, it has already been managed
            if (is_object($linkId)) {
                return $linkId;
            }
            
            // if link id is null, something wrong with the content and definition
            // this shouldn't happen ...
            if ($linkId === null) {
                /*
                $this->io->error([
                    "LINK ID ERROR",
                    "This happens when the definition 'name' is not an index in the CSV content row, this is likely because the ex.json does not match the CSV file headers.",
                    "Possible fix #2: Make sure the column name is not falsey, eg: 'false', '0', 'null', etc.",
                    "Possible fix #2: Make sure the ex.json file is up to date",
                ]);
                
                file_put_contents(__DIR__.'/LinkIdNull.json', json_encode($content, JSON_PRETTY_PRINT));
                $this->io->table(
                    [ 'LinkID', 'ContentID', 'ContentName', 'Definition', 'Depth', ],
                    [
                        [
                            $linkId,
                            $contentId,
                            $contentName,
                            json_encode($definition, JSON_PRETTY_PRINT),
                            $depth
                        ]
                    ]
                );
                
                die;
                */
           
                return $content;
            }
            
            // depth reached
            if ($depth > $this->maxDepth) {
                return null;
            }
            
            // linkId is 0 and linkTarget is not in our zero content list
            if ($linkId == 0 && in_array($linkTarget, self::ZERO_CONTENT) == false) {
                return null;
            }
            
            # $this->io->text("<info>[LINK {$depth}]</info> {$contentId} {$contentName} : {$definition->name} ---> {$linkId} {$linkTarget}");
            
            // if the content links to itself, then return back
            if ($contentName == $linkTarget && (int)$contentId == (int)$linkId) {
                return null;
            }
            
            // grab linked data
            $linkData = $this->linkContent($linkId, $linkTarget, ($contentName == $linkTarget) ? 99 : $depth);
            
            // append on linked data if it exists
            $content->{$definition->name} = $linkData ?: $content->{$definition->name};
            
            // save connection
            if ($linkData) {
                $this->saveConnection($contentId, $contentName, $definition->name, $linkId, $linkTarget);
            }

            unset($linkData);
            return null;
        }
        
        return null;
    }
    
    /**
     * Link content
     */
    private function linkContent($linkId, $linkTarget, $depth)
    {
        // linkId is 0 and linkTarget is not in our zero content list
        if ($linkId == 0 && in_array($linkTarget, self::ZERO_CONTENT) == false) {
            return null;
        }
    
        $targetContent = FileSystemCache::get($linkTarget, $linkId);
        $targetSchema  = $this->schema[$linkTarget] ?? null;
        
        // no content? return null
        if (!$targetContent) {
            return null;
        }
        
        // if no schema, return just the value
        if (!$targetSchema) {
            return $targetContent;
        }
        
        return $this->buildContent($linkId, $linkTarget, $targetSchema, clone $targetContent, $depth);
    }
    
    /**
     * Save the content connection
     */
    private function saveConnection($contentId, $contentName, $definitionName, $linkId, $linkTarget)
    {
        // linkId is 0 and linkTarget is not in our zero content list
        if ($linkId == 0 && in_array($linkTarget, self::ZERO_CONTENT) == false) {
            return null;
        }
        
        if (!isset($this->links)) {
            $this->links = [];
        }
        
        $this->links["{$linkTarget}_{$linkId}"]["{$contentName}_{$contentId}_{$definitionName}"] = (object)[
            'ContentColumnName' => $definitionName,
            'ContentName'       => $contentName,
            'ContentId'         => $contentId,
            'LinkTarget'        => $linkTarget,
            'LinkTargetID'      => $linkId,
        ];
    }
    
    /**
     * Save ID
     */
    private function saveContentId($contentId, $contentName)
    {
        if (!isset($this->ids[$contentName])) {
            $this->ids[$contentName] = [];
        }
    
        if (!in_array($contentId, $this->ids[$contentName])) {
            $this->ids[$contentName][] = $contentId;
        }
    }
}
