
本人博客地址:https://www.cnblogs.com/iifeng/ 没什么好说的都是干活,学起来,积累起来。

框架第一版路由规则:
PATH = local.lff.com  
这里根据你自己配置的本地域名拼接

PATH + /index.php?m=index&c=index/test

m: 对应model层,可以直接访问到model层的方法
c: 对应控制器层/方法
  第一版路由规则简单,根据ulr规则直接渲染mc层操作。
  如果想直接访问控制器层,可以忽略m传参。

此框架研究讨论,融合自己实战经验,将多种功能组合起来,主要使用php各种第三方库,demo小样。
删除git add 的内容  git rm -rf --cached .
使用本框架中第三方库,请composer update 自动安装所有composer.json中的库

1.phpjwt 的使用,方法
http://local.lff.com/index.php?c=fwt/test


