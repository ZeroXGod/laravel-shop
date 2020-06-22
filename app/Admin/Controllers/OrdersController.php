<?php

namespace App\Admin\Controllers;

use App\Exceptions\InvalidRequestException;
use App\Models\Order;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Foundation\Validation\ValidatesRequests;
use App\Http\Requests\Admin\HandleRefundRequest;
use App\Exceptions\InternalException;

class OrdersController extends AdminController
{
    use ValidatesRequests;

    protected $title = '订单';

    protected function grid()
    {
        $grid = new Grid(new Order());

        $grid->model()->whereNotNull('paid_at')->orderBy('paid_at', 'desc');
        $grid->no('订单流水号');
        $grid->column('user.name', '买家');
        $grid->total_amount('总金额')->sortable();
        $grid->paid_at('支付时间')->sortable();

        $grid->ship_status('物流')->display(function ($value) {
            return Order::$shipStatusMap[$value];
        });

        $grid->refund_status('退款状态')->display(function ($value) {
            return Order::$refundStatusMap[$value];
        });

        // 禁用创建按钮，后台不需要创建订单
        $grid->disableCreateButton();
        $grid->actions(function ($actions) {
            // 禁用删除和编辑按钮
            $actions->disableDelete();
            $actions->disableEdit();
        });
        $grid->tools(function ($tools) {
            // 禁用批量删除按钮
            $tools->batch(function ($batch) {
                $batch->disableDelete();
            });
        });

        return $grid;
    }


    public function show($id, Content $content)
    {
        return $content
            ->header('查看订单')
            // body 方法可以接受 Laravel 的视图作为参数
            ->body(view('admin.orders.show', ['order' => Order::find($id)]));

    }

    public function ship(Order $order, Request $request)
    {
        // 判断当前订单是否已支付
        if (!$order->paid_at) {
            throw new InvalidRequestException('该订单未付款');
        }

        // 判断当前订单发货状态是否为未发货
        if ($order->ship_status !== Order::SHIP_STATUS_PENDING) {
            throw new InvalidRequestException('该订单已发货');
        }

        // Laravel 5.5 之后 validate 方法可以返回校验过的值
        $data = $this->validate($request, [
            'express_company' => ['required'],
            'express_no' => ['required'],

        ], [], [
            'express_company' => '物流公司',
            'express_no' => '物流单号',
        ]);

        // 将订单发货状态改为已发货，并存入物流信息
        $order->update([
            'ship_status' => Order::SHIP_STATUS_DELIVERED,
            'ship_data' => $data,
        ]);

        // 返回上一页
        return redirect()->back();
    }

    public function handleRefund(Order $order, HandleRefundRequest $request)
    {
        // 判断订单状态是否正确
        if ($order->refund_status !== Order::REFUND_STATUS_APPLIED) {
            throw new InvalidRequestException('订单状态不正确');
        }
        // 是否同意退款
        if ($request->input('agree')) {
            // 清空退款理由
            $extra = $order->extra ?:[];
            unset($extra['refund_disagree_reason']);
            $order->update([
               'extra' => $extra,
            ]);
            // 调用退款逻辑
            $this->_refundOrder($order);
        } else {
            // 将拒绝退款理由放到订单的 extra 字段中
            $extra = $order->extra ?: [];
            $extra['refund_disagree_reason'] = $request->input('reason');
            // 将订单的退款状态改为未退款
            $order->update([
               'refund_status' => Order::REFUND_STATUS_PENDING,
               'extra' => $extra,
            ]);
        }

        return $order;
    }

    protected function _refundOrder(Order $order)
    {
        // 判断该订单的支付方式
        switch ($order->payment_method) {
            case 'wechat':
                // 微信先留空
                // todo
                break;
            case 'alipay':
                $refundNo = Order::getAvailableRefundNo();
                // 调用支付宝支付实例的 refund 方法
                $ret = app('alipay')->refund([
                   'out_trade_no' => $order->no,            // 订单流水号
                   'refund_amount' => $order->total_amount, // 退款金额
                    'out_request_no' => $refundNo, // 退款订单号
                ]);

                // 根据支付宝的文档，如果返回值里有 sub_code 字段说明退款shib
                if ($ret->sub_code) {
                    // 将退款失败的保存存入 extra 字段
                    $extra = $order->extra;
                    $extra['refund_failed_code'] = $ret->subc_code;
                    // 将订单的退款状态标记为退款失败
                    $order->update([
                       'refund_no' => $refundNo,
                       'refund_status' => Order::REFUND_STATUS_FAILED,
                       'extra' => $extra,
                    ]);
                } else {
                    // 将订单的退款状态标记为退款成功并保存退款单号
                    $order->update([
                       'refund_no' => $refundNo,
                       'refund_status' => Order::REFUND_STATUS_SUCCESS,
                    ]);
                }
                break;
            default:
                throw new InternalException('未知订单支付方式:' . $order->payment_method);
                break;
        }
    }

}