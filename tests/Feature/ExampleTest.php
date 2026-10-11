<?php

test('returns a successful response', function () {
    $response = $this->get(route('events.index'));

    $response->assertOk();
});
