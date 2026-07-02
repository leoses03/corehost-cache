<?php
t_assert(CHC_Role_Gate::should_bypass(['administrator'], ['administrator','editor']) === true, 'rol excluido => bypass');
t_assert(CHC_Role_Gate::should_bypass(['customer'], ['administrator','editor']) === false, 'rol permitido => no bypass');
t_assert(CHC_Role_Gate::should_bypass(['subscriber','customer'], ['customer']) === true, 'intersección => bypass');
t_assert(CHC_Role_Gate::should_bypass([], ['administrator']) === false, 'sin roles => no bypass');
t_assert(CHC_Role_Gate::should_bypass(['shop_manager'], []) === false, 'sin exclusiones => no bypass');
