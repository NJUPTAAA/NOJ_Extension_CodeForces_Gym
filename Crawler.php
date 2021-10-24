<?php

namespace App\Babel\Extension\gym;

use App\Babel\Crawl\CrawlerBase;
use App\Models\ProblemModel;
use App\Models\Eloquent\Problem;
use App\Models\OJModel;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;
use Log;
use Throwable;

class Crawler extends CrawlerBase
{
    public $oid = null;
    public $prefix = "GYM";
    private $currentProblemCcode;
    private $imageIndex;

    public function start($conf)
    {
        $action = $conf["action"];
        $con = $conf["con"];
        $cached = $conf["cached"];
        $range = $conf["range"];
        $this->oid = OJModel::oid('gym');

        if (blank($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }

        if ($action == 'judge_level') {
            $this->judge_level();
        } else {
            $this->crawl($con, $cached, $action == 'update_problem', $range);
        }
    }

    public function judge_level()
    {
        // Deprecated
    }

    public function extractCodeForces($pcode, $url, $retries = 5)
    {
        $failed = true;
        $status = false;

        foreach (range(1, $retries) as $tries) {
            try {
                $status = $this->_extractCodeForces($pcode, $url);
            } catch (Throwable $e) {
                Log::alert($e);
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>{$e->getMessage()}</>\n");
                continue;
            }
            $failed = false;
            break;
        }

        if ($failed) {
            throw new Exception('Failed after multiple tries.');
        }

        return $status;
    }

    private function getCodeForcesResponse($url)
    {
        return Requests::get($url, ['Referer' => 'https://codeforces.com'], [
            'verify' => babel_path("Cookies/cacert.pem"),
            'timeout' => 30
        ]);
    }

    private function globalizeCodeForcesURL($localizedURL)
    {
        if (strpos($localizedURL, '://') !== false) {
            $url = $localizedURL;
        } elseif ($localizedURL[0] == '/') {
            $url = 'https://codeforces.com' . $localizedURL;
        } else {
            $url = 'https://codeforces.com/' . $localizedURL;
        }
        return $url;
    }

    private function _extractCodeForces($pcode, $url)
    {
        $this->currentProblemCcode = $pcode;
        $this->imageIndex = 1;

        $this->pro["input"] = null;
        $this->pro["output"] = null;
        $this->pro["note"] = null;
        $this->pro["sample"] = null;
        $this->pro["description"] = null;

        $response = $this->getCodeForcesResponse($url);
        $contentType = $response->headers['content-type'];
        $content = $response->body;

        if (stripos($content, "<title>Attachments") !== false) {
            // refetching actual attachment
            $attachmentDOM = HtmlDomParser::str_get_html($content, true, true, DEFAULT_TARGET_CHARSET, false);
            $attachmentURL = $attachmentDOM->find('#pageContent div.datatable tbody tr a', 0)->href;
            $url = $this->globalizeCodeForcesURL($attachmentURL);
            $response = $this->getCodeForcesResponse($url);
            $contentType = $response->headers['content-type'];
            $content = $response->body;
        }

        if (stripos($content, "<title>Codeforces</title>") !== false) {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Problem not found.</>\n");
            return false;
        }

        if (strpos($content, 'Statement is not available on English language') !== false) {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Statement is not available on English.</>\n");
            return false;
        }

        if (stripos($contentType, "text/html") !== false) {
            $this->pro["file"] = 0;
            $this->pro["file_url"] = null;

            $problemDOM = HtmlDomParser::str_get_html($content, true, true, DEFAULT_TARGET_CHARSET, false);

            $this->pro["input_type"] = $problemDOM->find('div.problem-statement div.header .input-file', 0)->find('text', -1)->plaintext;
            $this->pro["output_type"] = $problemDOM->find('div.problem-statement div.header .output-file', 0)->find('text', -1)->plaintext;

            $problemDOM->find('div.problem-statement div.header', 0)->outertext = '';

            $inputSpecificationDOM = $problemDOM->find('div.problem-statement div.input-specification', 0);
            if (filled($inputSpecificationDOM)) {
                $inputSpecificationDOM->find('div.section-title', 0)->outertext = '';
                $this->pro["input"] = trim($inputSpecificationDOM->innertext);
                $inputSpecificationDOM->outertext = '';
            }

            $outputSpecificationDOM = $problemDOM->find('div.problem-statement div.output-specification', 0);
            if (filled($outputSpecificationDOM)) {
                $outputSpecificationDOM->find('div.section-title', 0)->outertext = '';
                $this->pro["output"] = trim($outputSpecificationDOM->innertext);
                $outputSpecificationDOM->outertext = '';
            }

            $noteDOM = $problemDOM->find('div.problem-statement div.note', 0);
            if(filled($noteDOM)) {
                $noteDOM->find('div.section-title', 0)->outertext = '';
                $this->pro["note"] = trim($noteDOM->innertext);
                $noteDOM->outertext = '';
            }

            $sampleTestsDOM = $problemDOM->find('div.problem-statement div.sample-tests', 0);

            if (filled($sampleTestsDOM)) {
                $sampleTestsDOM->find('div.section-title', 0)->outertext = '';
                $sampleCount = intval(count($sampleTestsDOM->find('pre')) / 2);
                $samples = [];
                for ($i = 0; $i < $sampleCount; $i++) {
                    $sampleInput = $sampleTestsDOM->find('pre')[$i * 2]->innertext;
                    $sampleOutput = $sampleTestsDOM->find('pre')[$i * 2 + 1]->innertext;
                    array_push($samples, [
                        "sample_input" => $sampleInput,
                        "sample_output" => $sampleOutput
                    ]);
                }
                $this->pro["sample"] = $samples;
                $sampleTestsDOM->outertext = '';
            }

            $descriptionSpecificationDOM = $problemDOM->find('div.problem-statement', 0);

            if(filled(trim(HtmlDomParser::str_get_html($descriptionSpecificationDOM->innertext, true, true, DEFAULT_TARGET_CHARSET, false)->plaintext))) {
                $this->pro["description"] = trim($descriptionSpecificationDOM->innertext);
            }

            if($this->pro["note"] == $this->pro["description"] && $this->pro["description"] == $this->pro["input"] && $this->pro["input"] == $this->pro["output"] && $this->pro["output"] == null) {
                $contestID = $this->pro['contest_id'];
                return $this->_extractCodeForces($pcode, "https://codeforces.com/gym/$contestID/attachments");
            }

            $this->pro["note"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["note"], true, true, DEFAULT_TARGET_CHARSET, false));
            $this->pro["description"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["description"], true, true, DEFAULT_TARGET_CHARSET, false));
            $this->pro["input"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["input"], true, true, DEFAULT_TARGET_CHARSET, false));
            $this->pro["output"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["output"], true, true, DEFAULT_TARGET_CHARSET, false));
        } else {
            if (stripos($contentType, "application/pdf") !== false) {
                $extension = "pdf";
            } elseif (stripos($contentType, "application/msword") !== false) {
                $extension = "doc";
            } elseif (stripos($contentType, "application/vnd.openxmlformats-officedocument.wordprocessingml.document") !== false) {
                $extension = "docx";
            } else {
                $extension = pathinfo($url, PATHINFO_EXTENSION);
            }
            $cacheDir = base_path("public/external/gym/$extension");
            if (!file_exists($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            file_put_contents(base_path("public/external/gym/$extension/$this->currentProblemCcode.$extension"), $content);
            $this->pro["description"] = '';
            $this->pro["file"] = 1;
            $this->pro["file_url"] = "/external/gym/$extension/$this->currentProblemCcode.$extension";
            $this->pro["sample"] = [];
        }
        return true;
    }

    private function cacheImage($dom)
    {
        if (!$dom) return null;
        foreach ($dom->find('img') as $imageElement) {
            $imageURL = $imageElement->src;
            $url = $this->globalizeCodeForcesURL($imageURL);
            $imageResponse = $this->getCodeForcesResponse($url);
            $extensions = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/bmp' => '.bmp'];
            if (isset($imageResponse->headers['content-type'])) {
                $extension = $extensions[$imageResponse->headers['content-type']];
            } else {
                $extension = pathinfo($imageElement->src, PATHINFO_EXTENSION);
            }
            $cachedImageName = $this->currentProblemCcode . '_' . ($this->imageIndex++) . $extension;
            $cacheDir = base_path("public/external/gym/img");
            if (!file_exists($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            file_put_contents(base_path("public/external/gym/img/$cachedImageName"), $imageResponse->body);
            $imageElement->src = '/external/gym/img/' . $cachedImageName;
        }
        return $dom;
    }

    private function getContestList($cached)
    {
        if ($cached) {
            $response = file_get_contents(__DIR__ . "/contest.list");
        } else {
            $response = $this->getCodeForcesResponse('https://codeforces.com/api/contest.list?gym=true')->body;
            file_put_contents(__DIR__ . "/contest.list", $response);
        }
        return $response;
    }

    private function getContestProblems($contest)
    {
        $contestID = $contest['id'];
        $problems = [];
        $targetURL = "https://codeforces.com/gym/$contestID";
        $response = $this->getCodeForcesResponse($targetURL);
        if ($response->status_code != 200) {
            throw new Exception("Contest page returned status code $response->status_code.");
        }
        $header = false;
        $parsedContestHTML = HtmlDomParser::str_get_html($response->body, true, true, DEFAULT_TARGET_CHARSET, false);
        foreach ($parsedContestHTML->find('table.problems tbody tr') as $problemCandidate) {
            if (!$header) {
                $header = true;
                continue;
            }
            $ncode = trim($problemCandidate->find('td', 0)->find('a', 0)->plaintext);
            $url = "https://codeforces.com/gym/$contestID/problem/$ncode";
            $pcode = $this->prefix . $contestID . $ncode;
            $title = trim($problemCandidate->find('td', 1)->find('a', 0)->plaintext);
            $limitations = trim(explode('</div>', $problemCandidate->find('td', 1)->find('div.notice', 0)->innertext, 2)[1]);
            [$timeLimit, $memoryLimit] = sscanf($limitations, "%d s, %d MB");
            $timeLimit *= 1000;
            $memoryLimit *= 1024;
            array_push($problems, [
                'ncode' => $ncode,
                'contest' => $contest,
                'url' => $url,
                'pcode' => $pcode,
                'title' => $title,
                'timeLimit' => $timeLimit,
                'memoryLimit' => $memoryLimit,
            ]);
        }
        return $problems;
    }

    private function crawlContest($contest, $incremental)
    {
        $startingMessage = $incremental ? 'Updating' : 'Crawling';
        $endingMessage = $incremental ? 'Updated' : 'Crawled';

        $failed = true;
        foreach (range(1, 5) as $tries) {
            try {
                $problems = $this->getContestProblems($contest, $incremental);
            } catch (Throwable $e) {
                Log::alert($e);
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>{$e->getMessage()}</>\n");
                continue;
            }
            $failed = false;
            break;
        }

        if ($failed) {
            throw new Exception('Failed after multiple tries.');
        }

        foreach ($problems as $problem) {
            $pcode = $problem['pcode'];
            if ($incremental && Problem::where('pcode', $pcode)->count()) {
                continue;
            }
            $this->line("  <fg=yellow>$startingMessage: </>$pcode");
            $status = $this->crawlProblem($problem);
            if($status) {
                $this->line("  <fg=green>$endingMessage:  </>$pcode");
            }
        }
    }

    public function crawl($con, $cached, $incremental, $range)
    {
        try {
            $response = $this->getContestList($cached);
            $contestList = json_decode($response, true);
            if ($contestList["status"] != "OK") {
                throw new Exception("Contest list status not OK.");
            }
        } catch (Throwable $e) {
            throw new Exception('Failed fetching problem set.');
        }

        $startingMessage = $incremental ? 'Updating Contest' : 'Crawling Contest';
        $endingMessage = $incremental ? 'Updated Contest' : 'Crawled Contest';

        foreach ($contestList['result'] as $contest) {
            $contestID = $contest['id'];
            $contestName = $contest['name'];

            if ($contest['phase'] != 'FINISHED') {
                continue;
            }

            if ($con != 'all') {
                if ($con != $contestID) {
                    continue;
                }
            } elseif ($this->inRange($contestID, $range) === false) {
                continue;
            }

            $this->line("<fg=yellow>$startingMessage:   </>$contestID $contestName");
            $this->crawlContest($contest, $incremental);
            $this->line("<fg=green>$endingMessage:    </>$contestID $contestName");
        }
    }

    private function resetProblem()
    {
        foreach ($this->pro as $x => $y) {
            $this->pro[$x] = null;
        }
    }

    private function crawlProblem($problem)
    {
        $this->resetProblem();

        $problemModel = new ProblemModel();

        $this->pro['origin'] = $problem['url'];
        $this->pro['source'] = $problem['contest']['name'];
        $this->pro['time_limit'] = $problem['timeLimit'];
        $this->pro['memory_limit'] = $problem['memoryLimit'];
        $this->pro['title'] = $problem['title'];
        $this->pro['solved_count'] = -1;
        $this->pro['pcode'] = $problem['pcode'];
        $this->pro['index_id'] = $problem['ncode'];
        $this->pro['contest_id'] = $problem['contest']['id'];
        $this->pro['OJ'] = $this->oid;
        $this->pro['tot_score'] = 1;
        $this->pro['partial'] = 0;
        $this->pro['markdown'] = 0;

        if (!$this->extractCodeForces($this->pro['pcode'], $this->pro['origin'])) {
            return false;
        }

        $pid = $problemModel->pid($this->pro['pcode']);

        if ($pid) {
            $problemModel->clearTags($pid);
            $this->updateProblem($this->oid);
        } else {
            $this->insertProblem($this->oid);
        }

        return true;
    }

    private function inRange($needle, $haystack)
    {
        $options = [];
        if (!is_null($haystack[0])) {
            $options['min_range'] = $haystack[0];
        }
        if (!is_null($haystack[1])) {
            $options['max_range'] = $haystack[1];
        }
        return filter_var($needle, FILTER_VALIDATE_INT, [
            'options' => $options
        ]);
    }
}
