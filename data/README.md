# data 目录说明

本目录存放 **ip2region** 的 IP 归属地数据库文件，用于记录下载 / 上传 IP 到市。

## 为什么仓库里没有这个文件

`ip2region_v4.xdb`（约 11MB）是 ip2region 项目的二进制数据文件，体积较大，
且由 [ip2region](https://github.com/lionsoul2014/ip2region) 项目持续更新，
因此不直接随本仓库分发，请部署时自行下载。

## 下载方法

```bash
cd data
wget https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region.xdb -O ip2region_v4.xdb
```

> 说明：`lib/Searcher.class.php` 与上面的 `data/ip2region_v4.xdb`
> 均来自 [lionsoul2014/ip2region](https://github.com/lionsoul2014/ip2region)，
> 遵循 Apache-2.0 协议，版权归原项目所有。

## 文件名约定

`lib.php` 里 `ip_region()` 函数固定读取 `data/ip2region_v4.xdb`，
下载后保持该文件名即可。
