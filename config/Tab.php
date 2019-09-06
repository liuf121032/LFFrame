<?php
namespace  config;
//定义各个 redis 表对应的key常量
class Tab{

    const Factory_User = "Factory_User_";//统计用户所有地块, Factory_User_Qid {地块集合}

    const Factory_Place = "Factory_Place";//通过地块ID查工厂信息 Factory_Place {$Key = server_region_pid => 工厂信息}

    const Factory_All = 'Factory_All'; //通过facID => server_region_pId

    const buildId = 'Factory_BuildId'; //工厂ID生成器表

    const userCard = 'user_card_'; //用户仓库

    //日志服务器 日志列表,产生的日志写入列表后端
    const LOG_QUEUE = "opt_log";

    //用户经验
    const USER_EXPERIENCE = "user_experience_"; // 等级称号经验

    const USER_TITLE = "user_title_"; // 等级称号//用户经验

    // 打工日志
    const USER_WORK_DAY = "workerSaleryByDay:"; // 按天打工日志

    const USER_WORK_ALL = "workerSaleryByProduct:"; // 按材料总日志

    //货币类型
    const Coin_Type = array(127 => '余额', 126 =>'金币', 125 =>'碎钻', 124 =>'钻石');

    //用户行为记录日志
    const OPT_FLOW_LOG = "opt_flow_log"; // 用户行为记录日志

}
