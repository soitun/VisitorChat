<?php
namespace UNL\VisitorChat\User\Status;

class Statistics
{
    /**
     * Get the total number of available users at a given time
     * 
     * In order to figure this out, we have to look at all the record BEFORE the given time.
     * 
     * @param $userIDs
     * @param $time
     * 
     * @return int
     */
    public function getTotalAvailableAtTime($userIDs, $time)
    {
        $total = 0;
        
        //Get everything before the start date.
        foreach (RecordList::getAllForUsersBetweenDates($userIDs, false, $time) as $status) {
            if ($status->status == 'AVAILABLE') {
                //Add to the total available.
                $total++;
            } else if ($total > 0) {
                //We may or may not have all the history in the db, so this MAY dip below 0. Don't allow that.
                $total--;
            }
        }
        
        return $total;
    }
    
    public function getStats($userIDs, $start = false, $end = false)
    {
        if (!$start) {
            $start = "2010-01-01 0:0:0";
        }

        if (!$end) {
            $end = \UNL\VisitorChat\Controller::epochToDateTime();
        }
        
        $total = $this->getTotalAvailableAtTime($userIDs, $start);
        
        $changes = array();
        $changes['statuses'] = array();
        
        //Give total at the start.
        $changes['statuses'][0]['start']       = strtotime($start) * 1000;
        $changes['statuses'][0]['total']       = $total;
        $changes['total_time_online']          = 0;
        $changes['total_time_online_business'] = 0;
        $changes['total_time']                 = 0;
        $changes['percent_online']             = 0;
        $changes['percent_online_business']    = 0;
        
        //Get everything between the dates
        $i = 1;
        
        foreach (RecordList::getAllForUsersBetweenDates($userIDs, $start, $end) as $status) {
            //Don't display status changes for new users (otherwise we would be subtracting 1 from the total and skewing the results).
            if ($status->reason == "NEW_USER") {
                continue;
            }
            
            if ($status->status == 'AVAILABLE') {
                //Add to the total available.
                $total++;
            } else if ($total > 0) {
                //We may or may not have all the history in the db, so this MAY dip below 0. Don't allow that.
                $total--;
            }
            
            $changes['statuses'][$i-1]['end']  = strtotime($status->date_created) * 1000;
            $changes['statuses'][$i]['start']  = strtotime($status->date_created) * 1000;
            $changes['statuses'][$i]['total']  = $total;
            $changes['statuses'][$i]['user']   = $status->getUser()->name;
            $changes['statuses'][$i]['reason'] = $status->reason;
            $changes['statuses'][$i]['status'] = $status->status;

            $i++;
        }

        $changes['statuses'][$i-1]['end'] = strtotime($end);
        
        if (strtotime($end) > time()) {
            $changes['statuses'][$i-1]['end'] = time();
        } 
        
        $changes['statuses'][$i-1]['end'] = $changes['statuses'][$i-1]['end'] * 1000;
        
        //Calculate percents and total times.
        
        $changes['total_time'] = strtotime($end) - strtotime($start);
        
        //Add total time online.
        foreach ($changes['statuses'] as $change) {
            if ($change['total'] > 0) {
                $changes['total_time_online'] += ($change['end']/1000 - ($change['start']/1000));
                
                $tmpStart = new \DateTime(date("r", $change['start']/1000));
                $tmpEnd   = new \DateTime(date("r", $change['end']/1000));

                if (($tmpStart->format("N") > 0 && $tmpStart->format("N") < 6
                    || $tmpEnd->format("N") > 0 && $tmpEnd->format("N") < 6)
                    && (($tmpStart->format("G") > 7 & $tmpStart->format("G") < 17) || ($tmpEnd->format("G") > 7 & $tmpEnd->format("G") < 17))) {
                    
                    if ($tmpStart->format("N") > 5) {
                        $diff = $tmpStart->format("N") - 5;
                        $tmpStart->modify("-$diff day");
                        
                        //it ends after the end of the business day... so set to the end of the business day.
                        $tmpStart->setTime(17, 0);
                    }

                    if ($tmpStart->format("G") < 8) {
                        $tmpStart->setTime(8, 0);
                    }

                    if ($tmpEnd->format("N") > 5) {
                        $diff = $tmpStart->format("N") - 5;
                        $tmpEnd->modify("-$diff day");

                        //it ends after the end of the business day... so set to the end of the business day.
                        $tmpEnd->setTime(17, 0);
                    }
                    
                    if ($tmpEnd->format("G") > 17) {
                        $tmpEnd->setTime(17, 0);
                    }

                    $changes['total_time_online_business'] += ($tmpEnd->getTimestamp() - $tmpStart->getTimestamp());
                }
            }
        }
        
        if ($changes['total_time'] > 0) {
            $changes['percent_online'] = round(($changes['total_time_online'] / $changes['total_time']) * 100, 2) . "%";
        }
        
        $totalDays = $changes['total_time'] / 86400;
        $totalBusinessSeconds = 0;

        $dateRange = new \DatePeriod(new \DateTime($start), new \DateInterval('P1D'), new \DateTime($end));
        
        foreach ($dateRange as $date) {
            $day = $date->format("N");
            
            //Skip weekends
            if (in_array($day, array(6, 7))) {
                continue;
            }
            
            $totalBusinessSeconds += 28800;
        }

        if ($changes['total_time_online_business'] > 0) {
            $changes['percent_online_business'] = round(($changes['total_time_online_business'] / $totalBusinessSeconds) * 100, 2) . "%";
        }
        
        return $changes;
    }
}