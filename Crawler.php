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

class Crawler extends CrawlerBase
{
    public $oid = null;
    public $prefix = "GYM";
    private $con;
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

    public function extractCodeForces($cid, $num, $url, $defaultDesc = "", $retries = 5)
    {
        $failed = true;
        $status = false;

        foreach (range(1, $retries) as $tries) {
            try {
                $status = $this->_extractCodeForces($cid, $num, $url, $defaultDesc);
            } catch (Exception $e) {
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

    private function _extractCodeForces($cid, $num, $url, $defaultDesc)
    {
        $pid = $cid . $num;
        $this->con = $pid;
        $this->imageIndex = 1;
        $response = Requests::get($url, ['Referer' => 'https://codeforces.com'], [
            'verify' => babel_path("Cookies/cacert.pem"),
            'timeout' => 30
        ]);
        $contentType = $response->headers['content-type'];
        $content = $response->body;
        if (stripos($content, "<title>Codeforces</title>") === false) {
            if (strpos($content, 'Statement is not available on English language') !== false) {
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Statement is not available on English.</>\n");
                return false;
            }
            if (stripos($content, "<title>Attachments") !== false) {
                $this->pro["description"] .= $defaultDesc;
            } else {
                if (stripos($contentType, "text/html") !== false) {
                    $this->pro["file"] = 0;
                    $this->pro["file_url"] = '';

                    $problemDOM = HtmlDomParser::str_get_html($content, true, true, DEFAULT_TARGET_CHARSET, false);

                    $first_step = explode('<div class="input-file"><div class="property-title">input</div>', $content);
                    $second_step = explode("</div>", $first_step[1]);
                    $this->pro["input_type"] = $second_step[0];
                    $first_step = explode('<div class="output-file"><div class="property-title">output</div>', $content);
                    $second_step = explode("</div>", $first_step[1]);
                    $this->pro["output_type"] = $second_step[0];

                    if (preg_match("/output<\\/div>.*<div>(.*)<\\/div>/sU", $content, $matches)) {
                        $this->pro["description"] .= str_replace('$$$$$$', '$$', trim(($matches[1])));
                    }

                    if (preg_match("/Input<\\/div>(.*)<\\/div>/sU", $content, $matches)) {
                        $this->pro["input"] = str_replace('$$$$$$', '$$', trim($matches[1]));
                    }

                    if (preg_match("/Output<\\/div>(.*)<\\/div>/sU", $content, $matches)) {
                        $this->pro["output"] = str_replace('$$$$$$', '$$', trim($matches[1]));
                    }

                    if (strpos($content, '<div class="sample-test">') !== false) {
                        $temp_sample = explode('<div class="sample-test">', $content)[1];
                        if (!(strpos($content, '<div class="note">') !== false)) {
                            $temp_sample = explode('<script type="text/javascript">', $temp_sample)[0];
                        } else {
                            $temp_sample = explode('<div class="note">', $temp_sample)[0];
                        }

                        $sampleListDOM = HtmlDomParser::str_get_html($temp_sample, true, true, DEFAULT_TARGET_CHARSET, false);
                        $sampleCount = intval(count($sampleListDOM->find('pre')) / 2);

                        $samples = [];
                        for ($i = 0; $i < $sampleCount; $i++) {
                            $sampleInput = $sampleListDOM->find('pre')[$i * 2]->innertext;
                            $sampleOutput = $sampleListDOM->find('pre')[$i * 2 + 1]->innertext;
                            array_push($samples, [
                                "sample_input" => $sampleInput,
                                "sample_output" => $sampleOutput
                            ]);
                        }
                        $this->pro["sample"] = $samples;
                    }

                    if (preg_match("/Note<\\/div>(.*)<\\/div><\\/div>/sU", $content, $matches)) {
                        $this->pro["note"] = trim(($matches[1]));
                        $this->pro["note"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["note"], true, true, DEFAULT_TARGET_CHARSET, false));
                    }

                    $this->pro["description"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["description"], true, true, DEFAULT_TARGET_CHARSET, false));
                    $this->pro["input"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["input"], true, true, DEFAULT_TARGET_CHARSET, false));
                    $this->pro["output"] = $this->cacheImage(HtmlDomParser::str_get_html($this->pro["output"], true, true, DEFAULT_TARGET_CHARSET, false));
                } else {
                    if (stripos($contentType, "application/pdf") !== false) {
                        $ext = "pdf";
                    } elseif (stripos($contentType, "application/msword") !== false) {
                        $ext = "doc";
                    } elseif (stripos($contentType, "application/vnd.openxmlformats-officedocument.wordprocessingml.document") !== false) {
                        $ext = "docx";
                    }
                    $dir = base_path("public/external/gym/pdf");
                    if (!file_exists($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents(base_path("public/external/gym/pdf/$cid$num.$ext"), $content);
                    $this->pro["description"] = '';
                    $this->pro["file"] = 1;
                    $this->pro["file_url"] = "/external/gym/pdf/$cid$num.$ext";
                    $this->pro["sample"] = [];
                }
            }
        } else {
            $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Problem not found.</>\n");
            return false;
        }
        return true;
    }

    private function cacheImage($dom)
    {
        if (!$dom) return $dom;
        foreach ($dom->find('img') as $ele) {
            $src = $ele->src;
            if (strpos($src, '://') !== false) {
                $url = $src;
            } elseif ($src[0] == '/') {
                $url = 'https://codeforces.com' . $src;
            } else {
                $url = 'https://codeforces.com/' . $src;
            }
            $res = Requests::get($url, ['Referer' => 'https://codeforces.com'], [
                'verify' => babel_path("Cookies/cacert.pem"),
                'timeout' => 30
            ]);
            $ext = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/gif' => '.gif', 'image/bmp' => '.bmp'];
            if (isset($res->headers['content-type'])) {
                $cext = $ext[$res->headers['content-type']];
            } else {
                $pos = strpos($ele->src, '.');
                if ($pos === false) {
                    $cext = '';
                } else {
                    $cext = substr($ele->src, $pos);
                }
            }
            $fn = $this->con . '_' . ($this->imageIndex++) . $cext;
            $dir = base_path("public/external/gym/img");
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(base_path("public/external/gym/img/$fn"), $res->body);
            $ele->src = '/external/gym/img/' . $fn;
        }
        return $dom;
    }

    private function getContestList($cached)
    {
        if ($cached) {
            $response = file_get_contents(__DIR__ . "/contest.list");
        } else {
            $response = Requests::get('https://codeforces.com/api/contest.list?gym=true', ['Referer' => 'https://codeforces.com'], [
                'verify' => babel_path("Cookies/cacert.pem"),
                'timeout' => 30
            ])->body;
            file_put_contents(__DIR__ . "/contest.list", $response);
        }
        return $response;
    }

    private function getContestProblems($contest)
    {
        $contestID = $contest['id'];
        $problems = [];
        $targetURL = "https://codeforces.com/gym/$contestID";
        $response = Requests::get($targetURL, ['Referer' => 'https://codeforces.com'], [
            'verify' => babel_path("Cookies/cacert.pem"),
            'timeout' => 30
        ]);
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
            } catch (Exception $e) {
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
            $this->crawlProblem($problem);
            $this->line("  <fg=green>$endingMessage:  </>$pcode");
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
        } catch (Exception $e) {
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
            $this->pro[$x] = '';
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

        if (!$this->extractCodeForces($this->pro['contest_id'], $this->pro['index_id'], $this->pro['origin'])) {
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
