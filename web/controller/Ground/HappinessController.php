<?php
namespace web\controller\Ground;

use web\controller\BaseController;
use web\model\HappinessModel;

class HappinessController extends BaseController
{
    /**
     * 购买幸福值前进行查询
     */
    public function selectHappiness()
    {
        $tvmid = get('tvmid');
        $service = get('service');
        $region = get('region');
        $gid = get('gid');
        $facid = get('facid');
        $token = get('token');
        if ($tvmid == '' || $service == '' || $region == '' || $gid == '' || $token == '' || $facid == '') {
            echo jsonOut(returnArr('410', '', 'param error'));
            exit;
        }

        $user = checkToken($tvmid, $token,"","");

        if (!$user) {
            echo jsonOut(returnArr('410', '', 'user error'));
            exit;
        }

        $result = HappinessModel::selectHappiness($service, $region, $gid, $facid, $tvmid);

        echo jsonOut($result);
        exit;
    }

    /**
     * 购买幸福值
     */
    public function buyHappiness()
    {
        $tvmid = post('tvmid');
        $service = post('service');
        $region = post('region');
        $gid = post('gid');
        $facid = post('facid');
        $token = post('token');
        if ($tvmid == '' || $service == '' || $region == '' || $gid == '' || $token == '' || $facid == '') {
            echo jsonOut(returnArr('410', '', 'param error'));
            exit;
        }

        $user = checkToken($tvmid, $token,"","");

        if (!$user) {
            echo jsonOut(returnArr('410', '', 'user error'));
            exit;
        }

        $result = HappinessModel::buyHappiness($service, $region, $gid, $facid, $tvmid);

        echo jsonOut($result);
        exit;
    }
}
