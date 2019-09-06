<?php
namespace web\controller\Ground;

use web\controller\BaseController;
use web\model\GrounddealModel;

class GrounddealController extends BaseController
{
    /**
     * 发起地块交易，method:1挂单交易 2拍块交易
     */
    public function submitOrder()
    {
        try {
            $tvmid = post('tvmid');
            $service = post('service');
            $region = post('region');
            $groundid = post('groundid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $coin = post('coin') ? post('coin') : '';
            $method = post('method') ? post('method') : '1';

            if ($tvmid == '' || $service == '' || $region == '' || $groundid == '' || $token == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }
            GrounddealModel::baseRequest('createorder',$tvmid . '_' . $service . '_' . $region . '_' . $groundid . '_' . $coin);
            $result = GrounddealModel::createOrder($tvmid, $service, $region, $groundid, $coin, $method);
            //$result = GrounddealModel::createOrder('wxh5848c20f603f7b352a442096','755','2','a1_6_21',510000,2);
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 地块交易取消
     */
    public function cancelOrder()
    {
        try {
            $tvmid = post('tvmid');
            $service = post('service');
            $region = post('region');
            $groundid = post('groundid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $orderid = post('orderid') ? post('orderid') : '';
            $method = post('method') ? post('method') : '1';

            if ($tvmid == '' || $service == '' || $region == '' || $groundid == '' || $token == '' || $orderid == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }
            GrounddealModel::baseRequest('cancelorder',$tvmid . '_' . $service . '_' . $region . '_' . $groundid . '_' . $orderid .'_'. $method);
            if ($method == 1) {
                $result = GrounddealModel::cancelOrder($tvmid, $service, $region, $groundid, $orderid);
            } else {
                $result = GrounddealModel::cancelAuction($tvmid, $service, $region, $groundid, $orderid);
            }
            //$result = GrounddealModel::cancelOrder('sjh591bc3e6d98ec65a3bc95bb7','755','2','a1_1_14','ccf3befc89a86494');
            //$result = GrounddealModel::cancelAuction('wxh5848c20f603f7b352a442096','755','2','a1_6_21','97c85093172f84bc66c3b191fe3d52cd');
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 交易挂单数量查询
     */
    public function dealCount()
    {
        try {
            $tvmid = post('tvmid');
            $service = post('service');
            $region = post('region');
            $groundid = post('groundid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';

            if ($tvmid == '' || $service == '' || $region == '' || $groundid == '' || $token == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }
            $result = GrounddealModel::dealCount($tvmid, $service, $region, $groundid);
            //$result = GrounddealModel::dealCount('wxh5848c20f603f7b352a442096','755','2','a5_11_14');
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 交易中心挂单列表
     */
    public function listOrder()
    {
        try {
            $tvmid = post('tvmid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $page = post('page') ? post('page') : '';
            $limit = post('limit') ? post('limit') : '';
            $type = post('type') ? post('type') : '';
            $groundtype = post('groundtype') ? post('groundtype') : '';
            $service = post('service') ? post('service') : '';
            $region = post('region') ? post('region') : '';
            $method = post('method') ? post('method') : '1';

            if ($tvmid == '' || $token == '' || $service == '' || $region == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }

            $result = GrounddealModel::listOrder($tvmid, $service, $region, $page, $limit, $type, $groundtype, $method);
            //$result = GrounddealModel::listOrder(1,7,1,2,'sjh591bc3e6d98ec65a3bc95bb7','755','2',2);
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 挂单销售结算处理
     */
    public function dealOrder()
    {
        try {
            $tvmid = post('tvmid');
            $service = post('service');
            $region = post('region');
            $groundid = post('groundid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $orderid = post('orderid') ? post('orderid') : '';

            if ($tvmid == '' || $service == '' || $region == '' || $groundid == '' || $token == '' || $orderid == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }
            GrounddealModel::baseRequest('dealorder',$tvmid . '_' . $service . '_' . $region . '_' . $groundid . '_' . $orderid . '_1');
            $result = GrounddealModel::dealOrder($tvmid, $service, $region, $groundid, $orderid, $nickname, $headimg);
            //$result = GrounddealModel::dealOrder('sjh591bc3e6d98ec65a3bc95bb7','755','2','a5_11_14','db4acfd12efc3b0ed146ed87142c5f32');
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 拍卖交易结算处理
     */
    public function dealAuction()
    {
        try {
            $tvmid = post('tvmid');
            $service = post('service');
            $region = post('region');
            $groundid = post('groundid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $orderid = post('orderid') ? post('orderid') : '';

            if ($tvmid == '' || $service == '' || $region == '' || $groundid == '' || $token == '' || $orderid == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }
            GrounddealModel::baseRequest('dealorder',$tvmid . '_' . $service . '_' . $region . '_' . $groundid . '_' . $orderid . '_2');
            $result = GrounddealModel::dealAuction($tvmid, $service, $region, $groundid, $orderid);
            //$result = GrounddealModel::dealAuction('wxh584b93b9a1d9223d37463758','755','2','a2_1_4','6b4bcb7a8dc9515f5698231a102456b7');
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 用户个人交易记录
     */
    public function userRecord()
    {
        try {
            $tvmid = post('tvmid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $groundtype = post('groundtype') ? post('groundtype') : '';
            $title = post('title') ? post('title') : '';
            $page = post('page') ? post('page') : '';
            $limit = post('limit') ? post('limit') : '';
            $method = post('method') ? post('method') : '1';

            if ($tvmid == '' || $token == '' || $groundtype == '' || $title == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }
            $result = GrounddealModel::userRecord($tvmid, $title, $groundtype, $page, $limit, $method);
            //$result = GrounddealModel::userRecord('wxh5848bcc2af119c3522ed1fbb','2','2',1,20,2);
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 用户个人交易记录删除
     */
    public function delUserrecord()
    {
        try {
            $tvmid = post('tvmid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $orderid = post('orderid') ? post('orderid') : '';
            $title = post('title') ? post('title') : '';

            if ($tvmid == '' || $token == '' || $title == '' || $orderid == '' || $title == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }
            $result = GrounddealModel::delRecord($tvmid, $orderid, $title);
            //$result = GrounddealModel::delRecord('sjh591bc3e6d98ec65a3bc95bb7','6b6a6485266b0b6f','1');
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 地块价格查询
     */
    public function groundPrice()
    {
        try {
            $tvmid = post('tvmid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $service = post('service') ? post('service') : '';
            $region = post('region') ? post('region') : '';
            $groundid = post('groundid') ? post('groundid') : '';
            $owner = post('owner') ? post('owner') : '';

            if ($tvmid == '' || $token == '' || $service == '' || $region == '' || $groundid == '' || $owner == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }
            $result = GrounddealModel::groundPrice($owner, $service, $region, $groundid);
            //$result = GrounddealModel::groundPrice('sjh591bc3e6d98ec65a3bc95bb7','755','2','a3_1_21');
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 拍卖竞拍出价
     */
    public function auctionOffer()
    {
        try {
            $tvmid = post('tvmid');
            $service = post('service');
            $region = post('region');
            $groundid = post('groundid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $orderid = post('orderid') ? post('orderid') : '';
            $coin = post('coin') ? post('coin') : '';

            if ($tvmid == '' || $service == '' || $region == '' || $groundid == '' || $token == '' || $orderid == '' || $coin == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }
            GrounddealModel::baseRequest('auctionoffer',$tvmid . '_' . $service . '_' . $region . '_' . $groundid . '_' . $orderid);
            $result = GrounddealModel::auctionOffer($tvmid, $service, $region, $groundid, $orderid, $coin);
            //$result = GrounddealModel::auctionOffer('sjh591bc3e6d98ec65a3bc95bb7','755','2','a1_6_21','97c85093172f84bc66c3b191fe3d52cd',510000);
            //$result = GrounddealModel::auctionOffer('wxh58675defff0f261b680b83b4','755','2','a1_6_21','97c85093172f84bc66c3b191fe3d52cd',530000);
            //$result = GrounddealModel::auctionOffer('sjh591bc3e6d98ec65a3bc95bb7','755','2','a1_6_21','97c85093172f84bc66c3b191fe3d52cd',550000);
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 拍卖出价排行
     */
    public function auctionList()
    {
        try {
            $tvmid = post('tvmid');
            $service = post('service');
            $region = post('region');
            $groundid = post('groundid');
            $token = post('token');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $orderid = post('orderid') ? post('orderid') : '';
            $page = post('page') ? post('page') : '';
            $limit = post('limit') ? post('limit') : '';

            if ($tvmid == '' || $service == '' || $region == '' || $groundid == '' || $token == '' || $orderid == '' || $page == '' || $limit == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }

            $result = GrounddealModel::auctionList($tvmid, $service, $region, $groundid, $orderid, $page, $limit);
            //$result = GrounddealModel::auctionList('wxh5848c20f603f7b352a442096','755','2','a1_6_21','97c85093172f84bc66c3b191fe3d52cd',1,9);
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }

    /**
     * 用户拍卖出价查询
     */
    public function userOffer()
    {
        try {
            $tvmid = post('tvmid');
            $token = post('token');
            $groundtype = post('groundtype');
            $nickname = post('nickname') ? post('nickname') : '';
            $headimg = post('headimg') ? post('headimg') : '';
            $page = post('page') ? post('page') : '';
            $limit = post('limit') ? post('limit') : '';

            if ($tvmid == '' || $page == '' || $limit == '' || $token == '' || $groundtype == '') {
                echo jsonOut(returnArr('410', '', 'param error'));
                exit;
            }

            $user = checkToken($tvmid, $token, $nickname, $headimg);

            if (!$user) {
                echo jsonOut(returnArr('410', '', 'user error'));
                exit;
            }

            $result = GrounddealModel::userOffer($tvmid, $groundtype, $page, $limit);
            //$result = GrounddealModel::userOffer('wxh584b93b9a1d9223d37463758','2','1','9');
            echo jsonOut($result);
        } catch(\Exception $e) {
            echo jsonOut(returnArr('410', '', $e->getMessage()));
            exit;
        }
    }
}