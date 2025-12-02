<?php

namespace App\Console\Commands;

use App\Models\PastPaper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PopulatePaperSlugs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'papers:populate-slugs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting slug population...");
        $processedCount = 0;
        $chunkSize = 500; // Process 500 records at a time

        // Use chunkById for efficient memory usage
        PastPaper::chunkById($chunkSize, function ($papers) use (&$processedCount) {
            
            // Start a database transaction for safety
            DB::beginTransaction();

            foreach ($papers as $paper) {
                // 1. Generate the slug from the paper_name
                $slug = Str::slug($paper->paper_name);
                
                // 2. Update the record
                $paper->paper_slug = $slug;
                $paper->save();

                $processedCount++;
            }

            DB::commit();
            $this->info("Processed $processedCount records...");
        });

        $this->info("Slug population finished successfully. Total records processed: $processedCount");
        return 0;
    }
}
